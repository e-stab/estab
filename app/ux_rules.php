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
 * The second body in this catalogue: how the application looks.
 *
 * SPEC.md section 5.10 says what the operation must achieve -- constancy of
 * place, constancy of element, the paper image, contrast, keyboard. It does
 * not say how tall a button is, and that is exactly where UX-ELEMENTKONSTANZ
 * fails in practice: two buttons of equal meaning look alike only while the
 * same person writes them on the same day.
 *
 * Appearance rules carry the GES- prefix and this origin. They share the
 * catalogue with the behavioural ones because they share their authority --
 * both are the operator's own decision and may be revised when they turn out
 * to serve nobody. They stay apart from the service-regulation catalogue for
 * the same reason as before: an audit asks what the regulation demands, and
 * that answer becomes useless once product decisions run through it.
 */
const ESTAB_UX_ORIGIN_GESTALTUNG =
    'Gestaltungsanforderungen des Betreibers, docs/GESTALTUNG.md';

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
                . 'Reihenfolge. Jeder Eintrag ist anklickbar; ein Ziel, das '
                . 'die eigene Funktion nicht ansteuern darf, erklärt das auf '
                . 'seiner eigenen Seite und nicht im Menü.',
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
            'requirement' => 'Auf jeder Seite ist erkennbar, für welche '
                . 'Führungsstelle gearbeitet wird, in welcher Funktion der '
                . 'Bedienende handelt und in welchem Bereich er sich '
                . 'befindet. Angaben, die sich während eines Einsatzes nicht '
                . 'ändern, stehen nicht dauerhaft im Blick.',
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
        /*
         * Zwei Stufen, eine Regel.
         *
         * Die Regel verlangte urspruenglich nur den AA-Wert 4.5:1. Der
         * Betreiber hat sie gehoben: 7:1 ist der Sollwert, 4.5:1 das absolute
         * Minimum, und gearbeitet wird am Sollwert. Der Grund ist nicht
         * Perfektionismus -- ein Laptopbildschirm im Einsatzraum steht unter
         * Deckenbeleuchtung oder im Tageslicht, oft schraeg im Blick. Der
         * gemessene Kontrast ist der beste Fall, nicht der tatsaechliche.
         *
         * Zwei Stufen und nicht eine, weil die Umstellung Zeit braucht: Eine
         * Regel, die 7:1 vom ersten Tag an ueberall verlangt, macht die Suite
         * monatelang rot, und eine rote Suite prueft nichts mehr. Die
         * 4.5-Stufe gilt deshalb ueberall und sofort, die 7-Stufe fuer jeden
         * Bereich, sobald er umgestellt ist.
         */
        'UX-KONTRAST' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Lesbarkeit auf farbigem Grund',
            'requirement' => 'Text erreicht 7:1 gegen seinen tatsächlichen '
                . 'Hintergrund und unterschreitet 4.5:1 niemals -- auch dort '
                . 'nicht, wo der Hintergrund die Farbe des amtlichen '
                . 'Vordrucks trägt. Eine Ausnahme gibt es nur für Farben, die '
                . 'die Dienstvorschrift vorgibt; sie wird namentlich '
                . 'vermerkt und bleibt über 4.5:1.',
        ],
        'UX-TASTATUR' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Bedienung mit der Tastatur',
            'requirement' => 'Die Anwendung ist vollständig mit der Tastatur '
                . 'bedienbar, einschliesslich der Feldhilfen. Die Sprungfolge '
                . 'folgt der Feldfolge des Vordrucks, und der Fokus ist '
                . 'sichtbar.',
        ],
        'UX-OHNE-JAVASCRIPT' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Laufweg ohne JavaScript',
            'requirement' => 'Aufnehmen, Sichten und Befördern einer '
                . 'Nachricht funktionieren ohne JavaScript. Der Komfort darf '
                . 'davon abhängen, der Nachrichtenlauf nicht.',
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
        'UX-MEINE-FELDER' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Zuständigkeit am Feld',
            'requirement' => 'Der Bedienende erkennt ohne Erklärung, welche '
                . 'Felder er in seiner Funktion in diesem Schritt '
                . 'auszufüllen hat. Fremde Felder bleiben sichtbar, sind '
                . 'aber nicht bedienbar und als schreibgeschützt benannt.',
        ],
        'UX-MEINE-FELDER-OHNE-FARBE' => [
            'origin' => ESTAB_UX_ORIGIN_BETREIBER,
            'reference' => 'Zuständigkeit ohne Farbe',
            'requirement' => 'Die Zuordnung läuft nicht allein über Farbe. '
                . 'Ohne jede Farbinformation bleibt erkennbar, welche Felder '
                . 'zuständig, welche fremd und welche Pflicht sind.',
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
        /*
         * Gestaltung. Herkunft docs/GESTALTUNG.md, Kennung GES-.
         *
         * Sie fuellen aus, was die Bedienanforderungen offenlassen: Diese
         * verlangen Elementkonstanz, sagen aber nicht, wie gross ein Knopf
         * ist. Genau daran scheitert Konstanz in der Praxis.
         */
        'GES-MARKEN' => [
            'origin' => ESTAB_UX_ORIGIN_GESTALTUNG,
            'reference' => 'Abschnitt 1.5 und 2.4',
            'requirement' => 'Jede Farb-, Schrift-, Abstands- und '
                . 'Radiusangabe des Stylesheets kommt aus einer Marke im '
                . ':root-Block. Eine eigene Zahl in einer Regel ist ein '
                . 'Befund, kein Detail.',
        ],
        'GES-SCHRIFTSKALA' => [
            'origin' => ESTAB_UX_ORIGIN_GESTALTUNG,
            'reference' => 'Abschnitt 2.3',
            'requirement' => 'Es gibt genau sieben Schriftgroessen. Die '
                . 'Arbeitsgroesse ist 0.875rem, der Nachrichteninhalt 1rem, '
                . 'und keine Angabe unterschreitet 0.75rem.',
        ],
        'GES-SCHRIFTSTAERKE' => [
            'origin' => ESTAB_UX_ORIGIN_GESTALTUNG,
            'reference' => 'Abschnitt 2.3',
            'requirement' => 'Es gibt genau die Schriftstaerken 400, 600 und '
                . '700. Staerken darueber sagen einen Unterschied zu, den die '
                . 'Schrift nicht hat.',
        ],
        'GES-ABSTANDSSKALA' => [
            'origin' => ESTAB_UX_ORIGIN_GESTALTUNG,
            'reference' => 'Abschnitt 2.2 und 2.5',
            'requirement' => 'Abstaende, Luecken und Innenabstaende stammen '
                . 'aus sieben Stufen, Eckradien aus vier. Auch der zweite '
                . 'Wert einer Kurzschreibweise stammt daraus.',
        ],
        'GES-KONTRAST-TEXT' => [
            'origin' => ESTAB_UX_ORIGIN_GESTALTUNG,
            'reference' => 'Abschnitt 2.4',
            'requirement' => 'Jeder Text erreicht 7:1 gegen den Grund, auf '
                . 'dem er tatsaechlich steht; 4.5:1 wird nie unterschritten. '
                . 'Der gemessene Wert ist der beste Fall, der Bildschirm im '
                . 'Einsatzraum nicht.',
        ],
        'GES-KONTRAST-RAND' => [
            'origin' => ESTAB_UX_ORIGIN_GESTALTUNG,
            'reference' => 'Abschnitt 2.4 und 2.6',
            'requirement' => 'Jeder Rand eines Bedienelements erreicht 3:1 '
                . 'gegen jeden Grund, auf dem er vorkommen kann. Vom '
                . 'Fokusring traegt auf jedem Grund mindestens einer seiner '
                . 'beiden Ringe.',
        ],
        'GES-FOKUS-DOPPELRING' => [
            'origin' => ESTAB_UX_ORIGIN_GESTALTUNG,
            'reference' => 'Abschnitt 2.6',
            'requirement' => 'Der Fokus wird von genau einer Regel gesetzt '
                . 'und besteht aus zwei Ringen -- aussen gold, innen dunkel. '
                . 'Auf jedem Grund der Anwendung traegt mindestens einer von '
                . 'beiden. Bei erzwungenen Farben tritt der Systemring an '
                . 'seine Stelle; `outline: none` ohne Ersatz gibt es nicht.',
        ],
        'GES-KEINE-BLASSE-SCHRIFT' => [
            'origin' => ESTAB_UX_ORIGIN_GESTALTUNG,
            'reference' => 'Abschnitt 2.4',
            'requirement' => 'Es gibt keine gedaempfte Schrift. Rangfolge '
                . 'entsteht ueber Groesse, Staerke und Ort, nie ueber '
                . 'Blaesse. Was zu unwichtig ist, um lesbar zu sein, ist zu '
                . 'unwichtig, um zu stehen.',
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
