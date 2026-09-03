<?php

declare(strict_types=1);

/**
 * Die Inbetriebnahme der ersten Dienstschicht als benannter Ablauf.
 *
 * Der strenge Berechtigungsmodus ist fachlich richtig und war trotzdem nicht
 * bedienbar: Die Schichtverwaltung zeigte jeden Schritt einzeln an, aber nie
 * den Weg. Wer einen Einsatz frisch in „streng" anlegte, fand ein
 * Auswahlfeld für Benutzerkonten ohne einen einzigen Eintrag, danach die
 * Zeile „Noch nicht angenommen: S2, Si, S6, LdF, Fernmelder" -- und keinen
 * Hinweis darauf, dass diese Annahme nicht die Administration erklärt,
 * sondern jede benannte Person selbst an ihrem eigenen Bildschirm. Der
 * einzige Knopf, der in dieser Lage noch sichtbar war, hieß „Planung
 * schließen".
 *
 * Deshalb steht der Ablauf hier als Zustand und nicht als Fließtext auf der
 * Seite: sechs Schritte, immer genau einer davon der aktuelle, jeder mit dem
 * Satz, wer ihn ausführt und wo. Die Funktion ist rein -- sie bekommt Zahlen
 * und Listen und gibt Sätze zurück. Das ist der Grund, warum sich der Ablauf
 * ohne Datenbank prüfen lässt und beim nächsten Umbau nicht still verrutscht.
 *
 * Reihenfolge und Wortlaut sind die Zusicherung. Ein Schritt, den man
 * überspringen kann, ist keiner; ein Schritt ohne Adresse ist eine Sackgasse.
 */

/*
 * Die Pflichtbesetzung steht in ESTAB_DV_REQUIRED_HATS und wird hier nicht
 * abgeschrieben. Eine zweite Liste desselben Inhalts wäre genau die Art
 * Doppelung, die irgendwann auseinanderläuft -- und dann sagt die
 * Ablaufführung etwas anderes als die Prüfung, die die Aktivierung sperrt.
 */
require_once __DIR__ . '/dv_operations.php';
require_once __DIR__ . '/function_label.php';

/**
 * Wo die persönliche Annahme stattfindet, von der Administration aus gesehen.
 *
 * Der Bereich heißt in der Navigation „Fernmeldeplan", die Annahme wohnt
 * darin im Abschnitt „Meine Dienstfunktionen". Beides zusammen muss dastehen,
 * sonst sucht ein S 2, dem man „nehmen Sie Ihre Funktion an" sagt, auf einer
 * Seite, deren Überschrift von Fernmeldebetrieb spricht.
 *
 * Bewusst ein Weg in Worten und keine Adresse: Die Administration geht ihn
 * nicht selbst -- sie kann es nicht, ihr Basiszugang ist kein
 * eStab-Funktionskonto -- sondern gibt ihn weiter.
 */
const ESTAB_DV_SHIFT_START_PERSONAL_LABEL =
    'Bereich „Fernmeldeplan“, Abschnitt „Meine Dienstfunktionen“';

/** Ein Schritt ist erledigt, gerade dran oder noch nicht an der Reihe. */
const ESTAB_DV_SHIFT_START_DONE = 'erledigt';
const ESTAB_DV_SHIFT_START_CURRENT = 'aktuell';
const ESTAB_DV_SHIFT_START_PENDING = 'offen';

/**
 * Die Lage der Inbetriebnahme aus den Listen ableiten, die die Seite ohnehin
 * liest.
 *
 * Reine Umformung: Benutzerliste und Schichtliste hinein, ein flacher Zustand
 * hinaus. Ohne diesen Zwischenschritt müsste die Prüfung die Datenbank
 * nachbauen, um einen Satz zu prüfen.
 *
 * @param list<array<string,mixed>> $users Zeilen aus estab_auth_fetch_users()
 * @param list<array<string,mixed>> $shifts Zeilen aus estab_dv_shift_list()
 * @return array{
 *   konten_frei:int,
 *   schicht:?array{nummer:int,bezeichnung:string},
 *   aktiv:bool,
 *   je_aktiviert:bool,
 *   zugewiesen:list<string>,
 *   angenommen:list<string>,
 *   wartend:list<array{funktion:string,person:string,kuerzel:string}>
 * }
 */
function estab_dv_shift_start_state(array $users, array $shifts): array
{
    $free = 0;
    foreach ($users as $user) {
        if ((int) ($user['estab_gesperrt'] ?? 1) !== 1) {
            $free++;
        }
    }

    $active = null;
    $planned = null;
    $everActivated = false;
    foreach ($shifts as $shift) {
        $status = (string) ($shift['status'] ?? '');
        if (($shift['aktiviert_am'] ?? null) !== null) {
            $everActivated = true;
        }
        if ($status === 'AKTIV') {
            $active = $shift;
            $everActivated = true;
            continue;
        }
        if ($status !== 'GEPLANT') {
            continue;
        }
        /*
         * Die Schicht der Inbetriebnahme ist die, die aktiviert werden kann:
         * geplant und ohne Vorgänger. estab_dv_activate_initial_shift()
         * verlangt genau das (`vorgaenger_id IS NULL`).
         *
         * Hier stand vorher „die erste geplante" und gemeint war die erste in
         * der Liste. estab_dv_shift_list() sortiert aber `nummer DESC` --
         * genommen wurde also die zuletzt angelegte. Wer neben einer fertig
         * besetzten Schicht #1 eine Schicht #2 als Platzhalter anlegte, bekam
         * die Meldung, es sei nichts besetzt, während unten der
         * Aktivierungsknopf von #1 stand.
         */
        if (($shift['vorgaenger_id'] ?? null) !== null) {
            continue;
        }
        if (
            $planned === null
            || (int) ($shift['nummer'] ?? 0) < (int) ($planned['nummer'] ?? 0)
        ) {
            $planned = $shift;
        }
    }
    /*
     * Solange keine Schicht läuft, führt die aktivierbare geplante durch den
     * Ablauf. Läuft eine, ist die Inbetriebnahme vorbei und die laufende
     * Schicht ist der Bezug -- eine daneben geplante Nachfolgeschicht gehört
     * zur Übergabe und nicht mehr zum Anfang.
     */
    $subject = $active ?? $planned;

    $assigned = [];
    $accepted = [];
    $waiting = [];
    foreach ((array) ($subject['besetzungen'] ?? []) as $hat) {
        if (!is_array($hat) || (int) ($hat['benutzer_gesperrt'] ?? 1) === 1) {
            continue;
        }
        $function = (string) ($hat['funktion'] ?? '');
        $status = (string) ($hat['status'] ?? '');
        if ($status === 'ANGENOMMEN') {
            $assigned[] = $function;
            $accepted[] = $function;
        } elseif ($status === 'ZUGEWIESEN') {
            $assigned[] = $function;
            $waiting[] = [
                'funktion' => $function,
                'person' => (string) ($hat['benutzer'] ?? ''),
                'kuerzel' => (string) ($hat['benutzer_kuerzel'] ?? ''),
            ];
        }
    }

    return [
        'konten_frei' => $free,
        'schicht' => $subject === null ? null : [
            'nummer' => (int) ($subject['nummer'] ?? 0),
            'bezeichnung' => (string) ($subject['bezeichnung'] ?? ''),
        ],
        'aktiv' => $active !== null,
        /*
         * Wurde in diesem Einsatz je eine Schicht aktiviert?
         *
         * Das entscheidet, ob die Inbetriebnahme noch bevorsteht. Nach einer
         * geschlossenen ersten Schicht bietet die Seite keinen
         * Aktivierungsknopf mehr an -- estab_dv_activate_initial_shift()
         * lehnt jede weitere Aktivierung ab, der Weg geht dann über die
         * Übergabe. Ein Ablauf, der weiter „Aktivieren Sie die Schicht
         * unten" sagt, zeigt auf einen Knopf, den es nicht gibt.
         */
        'je_aktiviert' => $everActivated,
        'zugewiesen' => array_values(array_unique($assigned)),
        'angenommen' => array_values(array_unique($accepted)),
        'wartend' => $waiting,
    ];
}

/**
 * Die Pflichtfunktionen, die in dieser Lage noch fehlen -- in der Reihenfolge
 * der Vorschrift, nicht alphabetisch.
 *
 * @param list<string> $vorhanden
 * @return list<string>
 */
function estab_dv_shift_start_missing(array $vorhanden): array
{
    return array_values(array_diff(ESTAB_DV_REQUIRED_HATS, $vorhanden));
}

/** Pflichtfunktionen für die Anzeige benennen: „S2, Si, S6, LdF, Fernmelder". */
function estab_dv_shift_start_function_text(array $funktionen): string
{
    return implode(
        ', ',
        array_map('estab_function_display_name', array_values($funktionen))
    );
}

/**
 * Den Ablauf der Inbetriebnahme als Liste von Schritten beschreiben.
 *
 * Genau ein Schritt trägt „aktuell": der erste, der noch nicht erledigt ist.
 * Alles davor ist erledigt, alles danach offen. Wer die Seite ansieht, muss
 * nicht ableiten, was als Nächstes dran ist -- es steht da.
 *
 * @param array{
 *   konten_frei:int,
 *   schicht:?array{nummer:int,bezeichnung:string},
 *   aktiv:bool,
 *   je_aktiviert:bool,
 *   zugewiesen:list<string>,
 *   angenommen:list<string>,
 *   wartend:list<array{funktion:string,person:string,kuerzel:string}>
 * } $lage
 * @return list<array{
 *   nummer:int, titel:string, zustand:string, wer:string, satz:string,
 *   namen:list<string>, ziel:?array{text:string,href:string}
 * }>
 */
function estab_dv_shift_start_steps(array $lage): array
{
    $free = $lage['konten_frei'];
    $shift = $lage['schicht'];
    $active = $lage['aktiv'];
    $everActivated = $lage['je_aktiviert'];
    $assigned = $lage['zugewiesen'];
    $accepted = $lage['angenommen'];
    $waiting = $lage['wartend'];

    $missingAssignment = estab_dv_shift_start_missing($assigned);
    $missingAcceptance = estab_dv_shift_start_missing($accepted);

    $waitingNames = [];
    foreach ($waiting as $entry) {
        $person = trim($entry['person']);
        $code = trim($entry['kuerzel']);
        $waitingNames[] = estab_function_display_name($entry['funktion'])
            . ' · ' . ($person === '' ? $code : $person . ' (' . $code . ')');
    }

    $steps = [
        [
            'titel' => 'Benutzerkonten anlegen',
            'fertig' => $free > 0,
            'wer' => 'Administration',
            'satz' => $free > 0
                ? $free . ' ungesperrte' . ($free === 1 ? 's Konto steht' : ' Konten stehen')
                    . ' zur Verfügung.'
                : 'Es gibt noch kein ungesperrtes Benutzerkonto. Eine '
                    . 'Dienstfunktion wird an ein Konto vergeben, nicht an '
                    . 'einen Namen — ohne Konto ist der nächste Schritt '
                    . 'nicht ausführbar.',
            'namen' => [],
            'ziel' => $free > 0
                ? null
                : ['text' => 'Benutzer verwalten', 'href' => 'users.php'],
        ],
        [
            'titel' => 'Dienstschicht planen',
            'fertig' => $shift !== null,
            'wer' => 'Administration',
            'satz' => $shift !== null
                ? 'Schicht #' . (int) $shift['nummer'] . ' · '
                    . (string) $shift['bezeichnung'] . ' ist angelegt.'
                : 'Legen Sie unten eine geplante Schicht mit einer '
                    . 'Bezeichnung an, unter der die Besetzung geführt wird.',
            'namen' => [],
            'ziel' => null,
        ],
        [
            'titel' => 'Pflichtfunktionen besetzen',
            'fertig' => $shift !== null && $missingAssignment === [],
            'wer' => 'Administration',
            'satz' => $shift === null
                ? 'Erst nach der geplanten Schicht besetzbar.'
                : ($missingAssignment === []
                    ? 'Alle fünf Pflichtfunktionen sind einem Konto '
                        . 'zugewiesen.'
                    : 'Diese Pflichtfunktionen sind noch keinem Konto '
                        . 'zugewiesen. Eine Person darf mehrere davon '
                        . 'tragen.'),
            'namen' => $shift === null
                ? []
                : array_map(
                    'estab_function_display_name',
                    $missingAssignment
                ),
            'ziel' => null,
        ],
        [
            'titel' => 'Persönliche Annahme',
            'fertig' => $shift !== null && $missingAcceptance === [],
            'wer' => 'Jede benannte Person selbst — nicht die Administration',
            'satz' => $shift === null || $missingAssignment !== []
                ? 'Sobald eine Funktion zugewiesen ist, meldet sich die '
                    . 'benannte Person mit ihrem eigenen Konto an und nimmt '
                    . 'sie im ' . ESTAB_DV_SHIFT_START_PERSONAL_LABEL
                    . ' verbindlich an.'
                : ($missingAcceptance === []
                    ? 'Alle Pflichtfunktionen sind persönlich angenommen.'
                    : 'Diese Zuweisungen warten auf die persönliche Annahme. '
                        . 'Die Administration kann sie nicht ersatzweise '
                        . 'erklären: Jede Person meldet sich mit ihrem '
                        . 'eigenen Konto an und nimmt im '
                        . ESTAB_DV_SHIFT_START_PERSONAL_LABEL . ' an.'),
            'namen' => $waitingNames,
            /*
             * Dieser Schritt bekommt kein Ziel.
             *
             * Er hatte eines -- einen Verweis auf die Annahmeseite. Der führt
             * die Administration aber gegen eine Anmeldewand: Die
             * Schichtverwaltung schützt der HTTP-Basiszugang, und der ist
             * kein eStab-Funktionskonto; die Annahmeseite verlangt genau ein
             * solches. Ein Verweis, der für seinen einzigen Leser nur
             * scheitern kann, ist keine Hilfe. Der Ort steht im Satz, und
             * weitergeben muss ihn ohnehin ein Mensch.
             */
            'ziel' => null,
        ],
        [
            'titel' => 'Schicht aktivieren',
            'fertig' => $active,
            'wer' => 'Administration',
            /*
             * „Aktivieren Sie die Schicht unten" darf nur dastehen, wenn
             * unten wirklich ein Knopf steht. Nach einer bereits aktivierten
             * Schicht bietet die Seite keinen mehr an, und
             * estab_dv_activate_initial_shift() lehnt die Aktivierung ab --
             * der Weg geht dann über die Übergabe.
             */
            'satz' => $active
                ? 'Der formale Dienstbetrieb läuft.'
                : ($everActivated
                    ? 'Die erste Dienstschicht war bereits aktiviert. Eine '
                        . 'weitere Schicht wird nicht aktiviert, sondern '
                        . 'über eine persönlich bestätigte Übergabe '
                        . 'übernommen.'
                    : ($shift !== null && $missingAcceptance === []
                        ? 'Alle Voraussetzungen stehen. Aktivieren Sie die '
                            . 'Schicht unten; damit werden ETB und TBB '
                            . 'eröffnet.'
                        : 'Erst nach der persönlichen Annahme aller '
                            . 'Pflichtfunktionen freigeschaltet.')),
            'namen' => [],
            'ziel' => null,
        ],
        [
            'titel' => 'Arbeitsfunktion wählen',
            /*
             * Dieser Schritt bleibt bewusst offen stehen.
             *
             * Ob jemand seine angenommene Funktion auch als Arbeitsfunktion
             * gewählt hat, ist eine Eigenschaft seiner Sitzung und keine der
             * Schicht. Die Administration kann es nicht sehen und nicht
             * abhaken -- sie kann es nur sagen. Ein Häkchen, das etwas
             * behauptet, was niemand geprüft hat, wäre schlimmer als keins.
             */
            'fertig' => false,
            'wer' => 'Jede Person selbst',
            'satz' => 'Nach der Annahme wählt jede Person im '
                . ESTAB_DV_SHIFT_START_PERSONAL_LABEL . ' zusätzlich ihre '
                . 'Arbeitsfunktion. Erst danach sind ihre operativen '
                . 'Bereiche freigeschaltet. Diesen Schritt sieht die '
                . 'Administration nicht; er gehört zur Sitzung der Person.',
            'namen' => [],
            'ziel' => null,
        ],
    ];

    /*
     * Die Inbetriebnahme ist ein einmaliger Vorgang, kein Dauerzustand.
     *
     * Vorher leiteten die Schritte 3 und 4 ihren Zustand immer aus der
     * gegenwärtigen Besetzung ab -- auch aus der der laufenden Schicht. Eine
     * Einzelablösung im laufenden Betrieb setzt die Nachbesetzung auf
     * ZUGEWIESEN, und damit sprang Schritt 4 zurück auf „jetzt dran", während
     * Schritt 5 „erledigt" trug. Die Liste las sich als Rückschritt, und der
     * Kasten darüber sagte gleichzeitig, es sei nur noch die Arbeitsfunktion
     * offen.
     *
     * Was einmal in Betrieb genommen wurde, bleibt in Betrieb genommen. Der
     * laufende Wechsel von Funktionsträgern gehört in die Tafel der aktiven
     * Schicht, wo er Zeile für Zeile steht -- nicht in die Erzählung des
     * Anfangs.
     */
    $inBetrieb = $active || $everActivated;
    if ($inBetrieb) {
        foreach ([0, 1, 2, 3, 4] as $index) {
            $steps[$index]['fertig'] = true;
            $steps[$index]['namen'] = [];
            $steps[$index]['ziel'] = null;
        }
        $steps[2]['satz'] = 'Die erste Dienstschicht war mit allen '
            . 'Pflichtfunktionen besetzt.';
        $steps[3]['satz'] = 'Alle Pflichtfunktionen der ersten Dienstschicht '
            . 'waren persönlich angenommen. Spätere Wechsel stehen in der '
            . 'Tafel der aktiven Schicht.';
    }

    $currentAssigned = false;
    $result = [];
    foreach ($steps as $index => $step) {
        if ($step['fertig']) {
            $state = ESTAB_DV_SHIFT_START_DONE;
        } elseif (!$currentAssigned) {
            $state = ESTAB_DV_SHIFT_START_CURRENT;
            $currentAssigned = true;
        } else {
            $state = ESTAB_DV_SHIFT_START_PENDING;
        }
        $result[] = [
            'nummer' => $index + 1,
            'titel' => $step['titel'],
            'zustand' => $state,
            'wer' => $step['wer'],
            'satz' => $step['satz'],
            'namen' => $step['namen'],
            'ziel' => $step['ziel'],
        ];
    }

    return $result;
}

/** Der eine Schritt, der gerade dran ist. */
function estab_dv_shift_start_current(array $steps): ?array
{
    foreach ($steps as $step) {
        if (($step['zustand'] ?? '') === ESTAB_DV_SHIFT_START_CURRENT) {
            return $step;
        }
    }
    return null;
}

/**
 * Die Ablaufführung als Liste ausgeben.
 *
 * Das Markup steht hier und nicht auf der Seite, weil es zum Zustand gehört:
 * Welcher Schritt sich abhebt, entscheidet die Ablauflogik, nicht die
 * Vorlage. So lässt es sich ohne Datenbank und ohne Webserver ansehen -- und
 * eine Prüfung kann feststellen, dass genau ein Schritt `aria-current`
 * trägt, statt es zu glauben.
 *
 * @param list<array<string,mixed>> $steps
 */
function estab_dv_shift_start_markup(array $steps): string
{
    $zustandstext = [
        ESTAB_DV_SHIFT_START_DONE => 'Erledigt',
        ESTAB_DV_SHIFT_START_CURRENT => 'Jetzt dran',
        ESTAB_DV_SHIFT_START_PENDING => 'Später',
    ];
    $html = static fn (mixed $wert): string => htmlspecialchars(
        (string) $wert,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $markup = '<ol class="estab-ablauf">';
    foreach ($steps as $step) {
        $zustand = (string) $step['zustand'];
        $markup .= '<li class="estab-ablauf-schritt estab-ablauf-'
            . $html($zustand) . '"'
            . ($zustand === ESTAB_DV_SHIFT_START_CURRENT
                ? ' aria-current="step"' : '')
            . '>'
            . '<p class="estab-ablauf-kopf">'
            . '<span class="estab-ablauf-nummer">'
            . (int) $step['nummer'] . '</span>'
            . '<span class="estab-ablauf-titel">'
            . $html($step['titel']) . '</span>'
            . '<span class="estab-ablauf-marke">'
            . $html($zustandstext[$zustand] ?? $zustand)
            . '</span></p>'
            . '<p class="estab-ablauf-wer">'
            . $html($step['wer']) . '</p>'
            . '<p>' . $html($step['satz']) . '</p>';
        if ($step['namen'] !== []) {
            $markup .= '<ul class="estab-ablauf-namen">';
            foreach ($step['namen'] as $name) {
                $markup .= '<li>' . $html($name) . '</li>';
            }
            $markup .= '</ul>';
        }
        if (is_array($step['ziel'])) {
            $markup .= '<p><a class="estab-button" href="'
                . $html($step['ziel']['href']) . '">'
                . $html($step['ziel']['text']) . '</a></p>';
        }
        $markup .= '</li>';
    }
    return $markup . '</ol>';
}
