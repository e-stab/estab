<?php

declare(strict_types=1);

/**
 * Catalogue of the operating requirements this application must uphold.
 *
 * These requirements do not come from a service regulation. They come from the
 * operator, and they exist because the application is used by people who know
 * the paper form and not the software. The yardstick is uncomfortable but
 * unambiguous: whoever falls back to paper during an operation because the
 * application got in the way has uncovered a defect of the application, not a
 * defect of their operating skill.
 *
 * The mechanism mirrors app/dv_rules.php exactly -- origin, reference,
 * requirement, loud failure on an unknown identifier, enforced test coverage.
 * The catalogues stay apart because they differ in authority, not in rigour:
 * a service regulation binds from outside and cannot be argued with, while an
 * operating requirement may be revised when it turns out to serve nobody.
 * Merging them would make it impossible to answer what the service regulation
 * demands, which is the question an audit asks.
 */

const ESTAB_UX_ORIGIN_BETREIBER =
    'Bedienanforderungen des Betreibers, SPEC.md Abschnitt 5.10';

/**
 * @return array<string, array{origin:string,reference:string,requirement:string}>
 */
function estab_ux_rules(): array
{
    return [
        'UX-MENUE-ORTSKONSTANZ' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Ortskonstanz der Navigation',
            'requirement' => 'Die Navigation steht auf jeder Seite an '
                . 'derselben Stelle, mit denselben Einträgen in derselben '
                . 'Reihenfolge. Ein Ziel, das die eigene Funktion gerade '
                . 'nicht ansteuern darf, bleibt sichtbar und inaktiv mit '
                . 'einem Grund; es verschwindet nicht.',
        ],
        'UX-MENUE-EIN-WEG' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Ein Weg je Ziel',
            'requirement' => 'Zu jedem Ziel führt genau ein Weg. Mehrere '
                . 'Einstiege in denselben Bereich verhalten sich gleich und '
                . 'nennen denselben Grund, wenn sie gesperrt sind.',
        ],
        'UX-STANDORT' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Standort auf jeder Seite',
            'requirement' => 'Auf jeder Seite ist erkennbar, für welchen '
                . 'Einsatz gearbeitet wird, in welcher Funktion der '
                . 'Bedienende handelt und in welchem Bereich er sich '
                . 'befindet.',
        ],
        'UX-EINE-SEITE' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Eine Seite je Arbeitsschritt',
            'requirement' => 'Alles, was ein Arbeitsschritt auszufüllen '
                . 'verlangt, steht auf einer Seite. Kein Assistent, keine '
                . 'Reiter, kein Weiterblättern zum Absenden.',
        ],
        'UX-PAPIERBILD' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Bild des Papiervordrucks',
            'requirement' => 'Die Oberfläche zeigt den Vordruck so, wie er '
                . 'auf Papier aussieht: dieselbe Feldfolge und dieselben drei '
                . 'Teile -- oben die Vermerke der Fernmeldezentrale, in der '
                . 'Mitte die Nachricht, unten der Laufzettel.',
        ],
        'UX-KEIN-BRUCH-IM-LAUFWEG' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Stationswechsel ohne Bruch',
            'requirement' => 'Der Wechsel der Station ändert das Bild des '
                . 'Vordrucks nicht, sondern nur, welche Felder bedienbar '
                . 'sind. Wer die Nachricht als Fernmelder gesehen hat, '
                . 'erkennt sie als Sichter wieder.',
        ],
        'UX-FLACHE-BILDSCHIRME' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Geräte der Führungsstelle',
            'requirement' => 'Die Anwendung ist auf den Geräten einer '
                . 'Führungsstelle bedienbar, einschließlich flacher '
                . 'Laptopbildschirme mit etwa 600 nutzbaren Bildpunkten '
                . 'Höhe. Die Bereichsnavigation bleibt dort erreichbar.',
        ],
        'UX-ELEMENTKONSTANZ' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Wiederkehrende Bedienelemente',
            'requirement' => 'Gleiche Bedeutung heisst gleiches '
                . 'Bedienelement, gleiche Beschriftung und gleiche Stelle. '
                . 'Ein Katalog legt die wiederkehrenden Elemente fest, und '
                . 'jede Oberfläche hält ihn ein.',
        ],
        'UX-INFOPOINTER' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Ausfüllhilfe am Feld',
            'requirement' => 'Jedes Feld trägt eine abrufbare Hilfe, die '
                . 'sagt, was einzutragen ist -- nicht, wie das Bedienelement '
                . 'zu benutzen ist.',
        ],
        'UX-RUECKMELDUNG' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Rückmeldung nach der Handlung',
            'requirement' => 'Nach jeder abgeschlossenen Handlung sagt die '
                . 'Anwendung, was geschehen ist, wohin die Nachricht gegangen '
                . 'ist und was als Nächstes ansteht.',
        ],
        'UX-SPRACHE-VORSCHRIFT' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Begriffe der Vorschrift',
            'requirement' => 'Feldbeschriftungen und Schaltflächen benutzen '
                . 'die Begriffe der Vorschrift statt Anwendungsjargon. Wer '
                . 'den Vordruck kennt, erkennt das Feld an seinem Namen '
                . 'wieder.',
        ],
    ];
}

/**
 * Resolve one rule, failing loudly on an identifier that does not exist.
 *
 * A service-regulation identifier does not resolve here. The two catalogues
 * must not quietly merge through a test that reaches into the wrong one.
 *
 * @return array{origin:string,reference:string,requirement:string}
 */
function estab_ux_rule(string $id): array
{
    $rules = estab_ux_rules();
    if (!array_key_exists($id, $rules)) {
        throw new InvalidArgumentException(
            'Unknown operating rule: ' . $id
        );
    }
    return $rules[$id];
}

/**
 * Message for a test that covers one rule.
 *
 * Calling this records the identifier when ESTAB_UX_COVERAGE names a file, so
 * the registry test can prove that every catalogued rule has a test.
 */
function estab_ux_requirement(string $id, string $detail = ''): string
{
    $rule = estab_ux_rule($id);
    $coverage = getenv('ESTAB_UX_COVERAGE');
    if (is_string($coverage) && $coverage !== '') {
        file_put_contents($coverage, $id . "\n", FILE_APPEND | LOCK_EX);
    }
    return '[' . $id . '] ' . $rule['origin'] . ', ' . $rule['reference']
        . ': ' . $rule['requirement']
        . ($detail === '' ? '' : ' — ' . $detail);
}
