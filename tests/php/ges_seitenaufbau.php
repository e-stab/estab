<?php

declare(strict_types=1);

/**
 * Der Seitenkopf ist eine Zeile, kein Banner -- und die Baender stehen fest.
 *
 * Jede Seite trug oben einmal einen grossen Balken mit Bereichsmarke, Titel
 * und einem erklaerenden Satz. Auf einem flachen Bildschirm ass das ein
 * Viertel der Hoehe fuer eine Angabe, die nach dem zweiten Aufruf niemand
 * mehr liest. Der Titel bleibt -- er sagt, wo man ist. Der Satz geht.
 *
 * Geprueft wird, was sich am Stylesheet und an den erzeugenden Stellen
 * ablesen laesst: dass der Kopf eine Zeile mit Unterlinie ist, dass der
 * Titel die vorgesehene Stufe traegt, dass die Bereichsmarke darueber steht
 * und dass ein erklaerender Absatz keine Hoehe bekommt.
 *
 * Was diese Pruefung nicht kann: die Reihenfolge der Baender im gerenderten
 * Dokument. Dafuer muesste sie die Seiten aufbauen, und das verlangt eine
 * Anmeldung, eine Datenbank und einen Einsatz. Die Reihenfolge steht deshalb
 * unter GES-BAENDER als Messung im Browser (blick), nicht hier.
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

/** Die Erklaerungen einer Regel, deren Auswaehler ein Muster trifft. */
$erklaerungen = static function (array $regeln, string $muster): array {
    $gefunden = [];
    foreach ($regeln as $regel) {
        if ($regel['kontext'] !== '') {
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

// Der Kopf des Vordrucks steht stellvertretend fuer alle Seitenkoepfe: Er
// ist der einzige, der auf einer eigenen Seite ohne Huelle steht.
$kopf = $erklaerungen($regeln, '~\A\.estab-message-page-header\z~');
$assert(
    $kopf !== [],
    estab_ux_requirement('GES-SEITENKOPF', 'Es gibt keinen Seitenkopf.')
);
$assert(
    ($kopf['background'] ?? '') === 'none' && ($kopf['box-shadow'] ?? '') === 'none',
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Seitenkopf traegt wieder eine Flaeche. Er ist eine Zeile, kein '
            . 'Banner: Ein Balken frisst auf einem flachen Bildschirm ein '
            . 'Viertel der Hoehe.'
    )
);
$assert(
    str_contains($kopf['border-bottom'] ?? '', '1px')
        && str_contains($kopf['border-bottom'] ?? '', 'var(--linie'),
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Seitenkopf hat keine Unterlinie aus einer Marke: '
            . ($kopf['border-bottom'] ?? 'gar keine')
    )
);

$titel = $erklaerungen($regeln, '~\.estab-message-page-header h1\z~');
$assert(
    ($titel['font-size'] ?? '') === 'var(--schrift-6)',
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Seitentitel traegt nicht die Titelstufe --schrift-6, sondern: '
            . ($titel['font-size'] ?? 'gar keine Angabe')
    )
);

// Der erklaerende Satz bekommt keine Hoehe. Er steht in einigen Koepfen noch
// im Markup; solange er das tut, muss die Regel ihn stillstellen.
$absatz = $erklaerungen($regeln, '~\.estab-message-page-header p\z~');
$assert(
    ($absatz['display'] ?? '') === 'none',
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Ein erklaerender Absatz im Seitenkopf bekommt wieder Hoehe.'
    )
);

// Die Bereichsmarke steht ueber dem Titel, nicht daneben, und sie ist die
// kleinste Stufe -- sie ordnet ein, sie ruft nicht.
$marke = $erklaerungen($regeln, '~\.estab-message-page-header \.estab-section-kicker~');
$assert(
    ($marke['order'] ?? '') === '-1',
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Die Bereichsmarke steht nicht vor dem Titel.'
    )
);
$assert(
    ($marke['font-size'] ?? '') === 'var(--schrift-1)'
        && str_contains($marke['text-transform'] ?? '', 'uppercase'),
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Die Bereichsmarke traegt nicht die kleinste Stufe in Versalien: '
            . ($marke['font-size'] ?? '?')
    )
);

// Genau ein h1 je erzeugendem Dokument. Mehrere Ueberschriften erster Ordnung
// nehmen einem Vorleseprogramm die Ordnung, an der es sich orientiert.
$vordruck = file_get_contents($root . '/4fach/official_message_form.php');
$assert(is_string($vordruck), 'Der Vordruck ist nicht lesbar.');
$assert(
    substr_count((string) $vordruck, '<h1>') === 1,
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Die Vordruckseite gibt nicht genau eine Ueberschrift erster Ordnung '
            . 'aus, sondern ' . substr_count((string) $vordruck, '<h1>') . '.'
    )
);

// Die Aktionsleiste ist ein eigenes Band und klebt am oberen Rand ihres
// Scrollbereichs. Sie muss decken -- sonst schiebt sich der Vordruck bei
// dichtem Satz sichtbar unter ihr durch.
$leiste = $erklaerungen($regeln, '~\A\.estab-message-actionbar\z~');
$assert(
    ($leiste['background'] ?? '') === 'var(--grund-tafel)',
    estab_ux_requirement(
        'GES-BAENDER',
        'Die klebende Aktionsleiste deckt nicht: ' . ($leiste['background'] ?? '?')
    )
);
$assert(
    str_contains($leiste['border-bottom'] ?? '', 'var(--linie'),
    estab_ux_requirement(
        'GES-BAENDER',
        'Die klebende Aktionsleiste traegt keine Unterlinie; die Kante '
            . 'zwischen Leiste und Blatt bliebe unbestimmt.'
    )
);

$leistenknopf = $erklaerungen($regeln, '~\.estab-message-actionbar \.estab-button\z~');
$assert(
    ($leistenknopf['min-height'] ?? '') === '2rem',
    estab_ux_requirement(
        'GES-BAENDER',
        'Die Knoepfe der Aktionsleiste sind nicht 2rem hoch, sondern: '
            . ($leistenknopf['min-height'] ?? 'unbestimmt')
    )
);

printf(
    "Gestaltung Seitenaufbau: OK (%d assertions)\n",
    $assertions
);
