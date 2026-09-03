<?php

declare(strict_types=1);

/**
 * Das Handbuch traegt die Gestaltung der Anwendung -- und jedes Wort zaehlt.
 *
 * > „Das Handbuch ist echt fragwuerdig. Es soll kein Marketingmaterial sein.
 * > Es soll sachlich in einfacher Sprache die Bedienung erklaeren."
 *
 * Zwei Befunde standen dahinter. Der erste war messbar: Das Handbuch trug
 * eine eigene Farbwelt, eine eigene Schrift und einen Banner mit Farbverlauf.
 * Wer aus dem Nachrichtenvordruck herueberkam, landete sichtbar in einem
 * anderen Programm. Der zweite war der Ton: „In 5 Minuten startklar",
 * „Oeffentlich und offline verfuegbar", „Verantwortung statt blosser
 * Menuefreigabe" -- neunzehn Kapitel mit je einem Werbesatz ueber der
 * Ueberschrift.
 *
 * Jetzt traegt die Seite die Klassen der Anwendung, und der Text sagt in
 * kurzen Saetzen, welchen Knopf man drueckt und was danach passiert.
 *
 * ## Warum die Wortzahl hier steht
 *
 * Der Text ist fachlich geprueft. Ihn beim naechsten Aufraeumen zu
 * „straffen" waere eine inhaltliche Aenderung im Gewand einer
 * gestalterischen. Dieser Test zaehlt deshalb die Woerter und bildet eine
 * Pruefsumme ueber ihre Menge: Wer eine Formulierung aendert, muss diese
 * Zahl bewusst mitaendern und den Grund in den Commit schreiben.
 *
 * Verglichen wird die *Menge* der Woerter, nicht ihre Reihenfolge -- Markup
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
 * Der Stand nach dem Neuschreiben.
 *
 * Von 5612 auf 4567 Woerter am 01.09.2026. Es fehlt kein Sachverhalt: Was
 * kuerzer wurde, waren Schachtelsaetze und die neunzehn Werbezeilen ueber
 * den Kapitelueberschriften. Aus einem Kapitel „Nachrichtenlauf" wurden
 * zwei, weil Ausgang und Eingang verschiedene Personen betreffen; die
 * Kurzreferenz war eine zweite Fassung des Inhaltsverzeichnisses und ist
 * entfallen. Neu dazugekommen sind der berichtigte Fernmeldeplan mit
 * Gegenstellen, Nebenstellen und Skizze, die eigene Seite fuer die
 * Melderauftraege und der LdF als Fuehrer des Technischen Betriebsbuchs.
 *
 * Von 4567 auf 4721 Woerter am 03.09.2026, Kapitel 15 „Schichten".
 *
 * Aus dem Betrieb kam die Meldung, der strenge Berechtigungsmodus
 * funktioniere nicht: Eine Dienstschicht liess sich nicht starten. Das
 * Kapitel beschrieb bis dahin in drei Schritten die *Zugangs*schicht; die
 * Dienstschicht bekam einen Satz. Es fehlte genau das, woran die
 * Inbetriebnahme scheiterte: die Reihenfolge, die Vorbedingung
 * Benutzerkonto, der Ort der persoenlichen Annahme und der eigene Schritt
 * „Arbeitsfunktion waehlen". Beide Listen tragen jetzt eine Ueberschrift,
 * damit die zweite nicht wieder fuer die erste gelesen wird.
 */
const ESTAB_HANDBUCH_WOERTER = 4721;
const ESTAB_HANDBUCH_PRUEFSUMME = '73fc8baa1ab4ee4c';

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

/* --- Die Seite traegt die Gestaltung der Anwendung --- */

$css = file_get_contents($root . '/handbuch/handbuch.css');
$assert(is_string($css), 'Das Stylesheet des Handbuchs ist nicht lesbar.');
$css = (string) $css;
$regeln = estab_test_css_regeln($css);

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

/*
 * Der Kopf kommt aus der gemeinsamen Schale.
 *
 * Frueher baute das Handbuch ihn selbst -- und deshalb konnte er sich von
 * jeder anderen Seite entfernen. Jetzt traegt er dieselben Klassen wie jede
 * Arbeitsseite und bekommt Flaeche, Titelgroesse und Unterlinie aus der
 * Kopfzeilenregel von estab-ui.css. Was hier geprueft wird, ist die
 * Zugehoerigkeit, nicht die Wiederholung der Regel.
 */
$assert(
    str_contains($quelle, 'class="estab-tool-page estab-handbook-page"')
        && str_contains($quelle, 'class="estab-tool-hero estab-handbook-hero"')
        && str_contains($quelle, 'class="estab-tool-eyebrow"')
        && str_contains($quelle, 'estab-tool-main'),
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Das Handbuch baut seinen Seitenkopf wieder selbst, statt die '
            . 'Schale der Anwendung zu tragen. Dann kann er sich von jeder '
            . 'anderen Seite entfernen, ohne dass es jemand merkt.'
    )
);

$kopf = $erklaerungen($regeln, '.estab-handbook-hero');
$assert(
    !isset($kopf['background'])
        && !isset($kopf['box-shadow'])
        && !isset($kopf['border-radius'])
        && !isset($kopf['color']),
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Kopf des Handbuchs bekommt wieder eine eigene Flaeche: '
            . implode(', ', array_keys($kopf))
    )
);
$assert(
    $erklaerungen($regeln, '.estab-handbook-hero h1') === []
        && $erklaerungen($regeln, '.estab-handbook-hero-inner::after') === [],
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Das Handbuch setzt seinem Titel wieder eine eigene Groesse oder '
            . 'stellt eine Zierform daneben. Auf einem flachen Bildschirm '
            . 'ist jede Zeile teuer, und ein Kreis ist keine Angabe.'
    )
);

/*
 * Der Kopf traegt nur die Bereichsmarke und den Titel.
 *
 * Hier stand ein erklaerender Satz und daneben "Stand ... Fassung ...".
 * Beides war unsichtbar, ohne dass es jemand merkte: Die Kopfzeilenregel der
 * Anwendung blendet in einem Seitenkopf jeden Absatz aus, der nicht die
 * Bereichsmarke ist. Ein Satz, der im Markup steht und nirgends erscheint,
 * ist schlimmer als keiner -- man haelt ihn fuer gesagt. Der Stand steht
 * jetzt im Fuss, wo er gelesen werden kann.
 */
$kopfAbsaetze = preg_match_all(
    '~<header class="estab-tool-hero estab-handbook-hero">(.*?)</header>~s',
    $quelle,
    $kopfTreffer
) === 1 ? $kopfTreffer[1][0] : '';
$assert(
    $kopfAbsaetze !== ''
        && substr_count($kopfAbsaetze, '<p') === 1
        && str_contains($kopfAbsaetze, 'class="estab-tool-eyebrow"')
        && substr_count($kopfAbsaetze, '<h1>') === 1,
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Kopf des Handbuchs traegt wieder mehr als Bereichsmarke und '
            . 'Titel. Was dort sonst steht, blendet die Kopfzeilenregel aus '
            . '-- es steht dann im Markup und nirgends auf dem Bildschirm.'
    )
);
$assert(
    !str_contains($quelle, 'estab-handbook-lead')
        && str_contains($quelle, 'Stand <?= estab_auth_html($handbookUpdated)'),
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Stand des Handbuchs steht nicht mehr im Fuss oder der '
            . 'erklaerende Satz ist in den Kopf zurueckgekehrt.'
    )
);

/*
 * Keine eigene Farbe.
 *
 * Das Handbuch trug elf eigene Farbwerte -- eigenes Grau, eigenes Blau,
 * eigenes Rot. Neben der Anwendung sah das aus wie ein anderes Programm.
 * Eine Farbe, die hier faellt, faellt nur hier; deshalb steht hier gar
 * keine mehr, sondern nur noch Marken aus estab-ui.css.
 */
$assert(
    preg_match('/#[0-9a-fA-F]{3,8}\b/', $css) !== 1,
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Das Handbuch traegt wieder eine eigene Farbe. Es soll die Marken '
            . 'der Anwendung verwenden, damit es nicht als anderes Programm '
            . 'gelesen wird.'
    )
);

/* --- Der Fliesstext bleibt lesbar breit --- */

$absatz = $erklaerungen(
    $regeln,
    '.estab-handbook-chapter p, .estab-handbook-chapter li'
);
$assert(
    ($absatz['max-width'] ?? '') === '34rem',
    estab_ux_requirement(
        'GES-SEITENKOPF',
        'Der Fliesstext des Handbuchs ist nicht auf 34rem begrenzt, sondern: '
            . ($absatz['max-width'] ?? 'gar nicht') . '. Eine Zeile ueber '
            . 'neunzig Zeichen verliert beim Ruecksprung ihren Anfang.'
    )
);

printf(
    "Handbuch: OK (%d assertions, %d Wörter unverändert)\n",
    $assertions,
    count($woerter)
);
