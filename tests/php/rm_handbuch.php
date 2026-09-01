<?php

declare(strict_types=1);

/**
 * Das Handbuch bekommt die Hülle -- und behält jedes Wort.
 *
 * > „Das Handbuch ist immer noch eine Katastrophe."
 *
 * Der Befund ist messbar: Auf einem Bildschirm mit 768 Bildpunkten Höhe --
 * der übliche Laptop in einer Führungsstelle -- nahm der Kopf des Handbuchs
 * 528 davon ein, 69 Prozent. Wer es öffnete, sah einen Werbebanner mit einem
 * dekorativen Kreis und musste scrollen, um den ersten Satz zu lesen.
 *
 * `GES-SEITENKOPF` verlangt für jede Seite dasselbe: eine Zeile mit
 * Bereichsmarke und Titel, keine Fläche, keinen Schatten, eine Unterlinie.
 * Das Handbuch hatte sich davon ausgenommen, weil es eigene Klassennamen
 * trägt und der Wächter sie nicht kennt.
 *
 * ## Warum die Wortzahl hier steht
 *
 * Ein Umbau der Gestaltung darf keinen Satz umschreiben. Der Text des
 * Handbuchs ist fachlich geprüft; ihn beim Aufräumen zu „straffen" wäre eine
 * inhaltliche Änderung im Gewand einer gestalterischen. Dieser Test zählt
 * deshalb die Wörter und bildet eine Prüfsumme über ihre Menge: Wer eine
 * Formulierung ändert, muss diese Zahl bewusst mitändern und den Grund in
 * den Commit schreiben.
 *
 * Verglichen wird die *Menge* der Wörter, nicht ihre Reihenfolge -- Markup
 * darf sich frei bewegen, der Text nicht verschwinden.
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

/*
 * Der Stand vor dem Umbau. Wer ihn ändert, ändert den Text des Handbuchs.
 *
 * Geändert am 01.09.2026 von 5614 auf 5612 Wörter: Die Seite
 * „Führungsstellenbetrieb" heißt jetzt „Fernmeldeplan", und die
 * Melderaufträge stehen daneben statt darunter. Das Handbuch nennt die
 * Bereiche beim Namen; ein Verweis auf einen Namen, den es nicht mehr gibt,
 * schickt den Lesenden ins Leere. Zwei Wörter weniger, weil aus „S6- und
 * Melderaufgaben" die „S6-Aufgaben" wurden -- die Melderaufgaben stehen
 * nicht mehr in diesem Bereich.
 */
const ESTAB_HANDBUCH_WOERTER = 5612;
const ESTAB_HANDBUCH_PRUEFSUMME = '5827ae6a5447792b';

$quelle = file_get_contents($root . '/handbuch/index.php');
$assert(is_string($quelle), 'Das Handbuch ist nicht lesbar.');
$quelle = (string) $quelle;

$text = preg_replace('~<\?php.*?\?>~s', ' ', $quelle) ?? '';
$text = preg_replace('~<\?=.*?\?>~s', ' ', $text) ?? '';
$text = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~s', ' ', $text) ?? '';
$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
preg_match_all('~\p{L}[\p{L}\p{N}\-]*~u', $text, $treffer);
$woerter = $treffer[0];
sort($woerter);
$pruefsumme = substr(hash('sha256', implode(' ', $woerter)), 0, 16);

$assert(
    count($woerter) === ESTAB_HANDBUCH_WOERTER,
    'Das Handbuch hat ' . count($woerter) . ' Wörter statt '
        . ESTAB_HANDBUCH_WOERTER . '. Ein Umbau der Gestaltung schreibt '
        . 'keinen Satz um; wer den Text ändert, ändert diese Zahl bewusst '
        . 'mit und begründet es.'
);
$assert(
    $pruefsumme === ESTAB_HANDBUCH_PRUEFSUMME,
    'Die Wörter des Handbuchs sind dieselben in der Zahl, aber nicht '
        . 'dieselben: ' . $pruefsumme . ' statt ' . ESTAB_HANDBUCH_PRUEFSUMME
        . '. Ein Wort wurde gegen ein anderes getauscht.'
);

/* --- Der Kopf ist eine Zeile, kein Banner --- */

$css = file_get_contents($root . '/handbuch/handbuch.css');
$assert(is_string($css), 'Das Stylesheet des Handbuchs ist nicht lesbar.');
$regeln = estab_test_css_regeln((string) $css);

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

$kopf = $erklaerungen($regeln, '.estab-handbook-hero');
$assert(
    ($kopf['background'] ?? '') === 'none' && ($kopf['box-shadow'] ?? '') === 'none',
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Kopf des Handbuchs trägt wieder eine Fläche. Er ist eine Zeile, '
            . 'kein Banner: Auf 768 Bildpunkten Höhe nahm der alte 528 davon '
            . 'ein, 69 Prozent, und der erste Satz stand unter dem Rand.'
    )
);
$assert(
    str_contains($kopf['border-bottom'] ?? '', 'var(--linie'),
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Kopf des Handbuchs hat keine Unterlinie aus einer Marke: '
            . ($kopf['border-bottom'] ?? 'gar keine')
    )
);

$titel = $erklaerungen($regeln, '.estab-handbook-hero h1');
$assert(
    ($titel['font-size'] ?? '') === 'var(--schrift-6)',
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Titel des Handbuchs trägt nicht die Titelstufe --schrift-6, '
            . 'sondern: ' . ($titel['font-size'] ?? 'gar keine Angabe')
    )
);

// Der dekorative Kreis und der Werbesatz kosten Höhe und sagen nichts.
$assert(
    $erklaerungen($regeln, '.estab-handbook-hero-inner::after') === [],
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Kopf trägt wieder eine Zierform. Auf einem flachen Bildschirm '
            . 'ist jede Zeile teuer, und ein Kreis ist keine Angabe.'
    )
);
$lead = $erklaerungen($regeln, '.estab-handbook-lead');
$assert(
    ($lead['display'] ?? '') === 'none',
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der erklärende Satz im Kopf bekommt wieder Höhe. Er steht im '
            . 'Markup und wird vorgelesen; sichtbar kostet er nur Platz.'
    )
);

/* --- Der Fließtext bleibt lesbar breit --- */

$absatz = $erklaerungen($regeln, '.estab-handbook-chapter p');
$assert(
    ($absatz['max-width'] ?? '') === '34rem',
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Fließtext des Handbuchs ist nicht auf 34rem begrenzt, sondern: '
            . ($absatz['max-width'] ?? 'gar nicht'). '. Eine Zeile über '
            . 'neunzig Zeichen verliert beim Rücksprung ihren Anfang.'
    )
);

printf(
    "Handbuch: OK (%d assertions, %d Wörter unverändert)\n",
    $assertions,
    count($woerter)
);
