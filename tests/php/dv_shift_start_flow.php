<?php

declare(strict_types=1);

/**
 * Die Inbetriebnahme der ersten Dienstschicht im strengen Modus.
 *
 * Am 03.09.2026 kam die Meldung, der strenge Berechtigungsmodus funktioniere
 * nicht: Eine Dienstschicht ließ sich nicht starten. Die Technik war in
 * Ordnung -- der Weg von einem frischen strengen Einsatz zur laufenden
 * Schicht ist nachweislich gangbar. Nicht gangbar war er für einen Menschen:
 *
 *   - Ohne Benutzerkonto rendete die Zuweisung ein Pflicht-Auswahlfeld mit
 *     null Einträgen und einen Knopf, der nur scheitern konnte.
 *   - Waren alle fünf Funktionen zugewiesen, stand dort „Noch nicht
 *     angenommen: S2, Si, S6, LdF, Fernmelder" -- und nirgends, dass diese
 *     Annahme nicht die Administration erklärt, sondern jede benannte Person
 *     selbst, mit ihrem eigenen Konto, an einer bestimmten Stelle.
 *   - Der einzige Knopf, der in dieser Lage noch sichtbar war, verwarf die
 *     Planung.
 *
 * Diese Prüfung hält den reparierten Ablauf fest: zuerst die reine
 * Ablauflogik ohne Datenbank, danach die Seite, die sie benutzt.
 *
 * Vier Zusicherungen stehen hier, weil eine adversarische Gegenlesung sie
 * eingefordert hat -- die erste Fassung war an genau diesen Stellen zu
 * gutgläubig:
 *
 *   - Die Wächter vor den Kontoauswahlen werden nicht gezählt, sondern
 *     zugeordnet. Eine Zählung bleibt grün, wenn ein Wächter zur falschen
 *     Auswahl gehört.
 *   - Die Faltung der Datenbanklisten wird auch ohne aktive Schicht geprüft;
 *     vorher war ausgerechnet der Inbetriebnahmepfad unbelegt.
 *   - Die Aufklappung vor „Planung schließen" wird als `details`/`summary`
 *     geprüft, nicht als Reihenfolge zweier Zeichenketten.
 *   - Der Planungskasten wird in beiden Zweigen geprüft, nicht nur auf das
 *     Vorhandensein einer seiner beiden Überschriften.
 *
 * Die Pflichtbesetzung wird nirgends abgeschrieben. Sie steht in
 * ESTAB_DV_REQUIRED_HATS, und die Prüfung besteht darauf, dass Ablaufführung
 * und Aktivierungssperre aus derselben Liste lesen.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once $root . '/app/dv_shift_start.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $relative) use ($root, $assert): string {
    $source = file_get_contents($root . '/' . $relative);
    $assert(
        is_string($source) && $source !== '',
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'Die Quelle ' . $relative . ' ist nicht lesbar.'
        )
    );

    return (string) $source;
};

/**
 * Jede Kontoauswahl im Quelltext ihrem Wächter zuordnen.
 *
 * Gezählt wurde vorher: drei Auswahlen, drei Wächter, grün. Damit bliebe
 * eine ungeschützte Auswahl neben einem doppelten Wächter unbemerkt. Hier
 * wird der Quelltext stattdessen durchlaufen und für jede Auswahl
 * festgestellt, in welchem Zweig welcher Bedingung sie steht.
 *
 * Die beiden Wächter arbeiten gegenläufig, und das ist Absicht: Der strenge
 * Zweig fragt „fehlt ein Konto?" und stellt die Auswahl in den Sonst-Zweig,
 * der lockere fragt „gibt es Konten?" und stellt sie in den Dann-Zweig.
 *
 * @param array<string,string> $waechter Bedingung => schützender Zweig
 * @return array{auswahlen:int,ungeschuetzt:list<int>}
 */
function shift_start_kontoauswahlen(string $quelle, array $waechter): array
{
    $marken = [];
    foreach (['<?php if (', '<?php else', '<?php endif'] as $muster) {
        $ort = 0;
        while (($ort = strpos($quelle, $muster, $ort)) !== false) {
            $marken[$ort] = $muster;
            $ort += strlen($muster);
        }
    }
    $auswahlen = 0;
    $ort = 0;
    while (
        ($ort = strpos($quelle, 'name="benutzer_kuerzel" required', $ort))
        !== false
    ) {
        $marken[$ort] = 'AUSWAHL';
        $auswahlen++;
        $ort += 10;
    }
    ksort($marken);

    $stapel = [];
    $ungeschuetzt = [];
    foreach ($marken as $ort => $art) {
        if ($art === '<?php if (') {
            $kopf = substr($quelle, $ort, 140);
            $zweig = null;
            foreach ($waechter as $bedingung => $schuetzenderZweig) {
                if (str_contains($kopf, $bedingung)) {
                    $zweig = $schuetzenderZweig;
                    break;
                }
            }
            $stapel[] = ['schuetzt' => $zweig, 'zweig' => 'dann'];
        } elseif ($art === '<?php else') {
            if ($stapel !== []) {
                $stapel[count($stapel) - 1]['zweig'] = 'sonst';
            }
        } elseif ($art === '<?php endif') {
            array_pop($stapel);
        } else {
            $geschuetzt = false;
            foreach ($stapel as $rahmen) {
                if (
                    $rahmen['schuetzt'] !== null
                    && $rahmen['schuetzt'] === $rahmen['zweig']
                ) {
                    $geschuetzt = true;
                    break;
                }
            }
            if (!$geschuetzt) {
                $ungeschuetzt[] = substr_count(substr($quelle, 0, $ort), "\n") + 1;
            }
        }
    }

    return ['auswahlen' => $auswahlen, 'ungeschuetzt' => $ungeschuetzt];
}

/** Eine Lage bauen, ohne jedes Mal alle Schlüssel zu schreiben. */
$lage = static function (array $abweichung = []): array {
    return $abweichung + [
        'konten_frei' => 0,
        'schicht' => null,
        'aktiv' => false,
        'je_aktiviert' => false,
        'zugewiesen' => [],
        'angenommen' => [],
        'wartend' => [],
    ];
};
$schicht = ['nummer' => 1, 'bezeichnung' => 'Tagschicht'];
$alle = ESTAB_DV_REQUIRED_HATS;
$wartet = static fn (string $f, string $person, string $kuerzel): array =>
    ['funktion' => $f, 'person' => $person, 'kuerzel' => $kuerzel];

// ---------------------------------------------------------------------------
// 1. Die Lagen des Betriebs und der Schritt, der in ihnen ansteht.
// ---------------------------------------------------------------------------

$lagen = [
    'leer' => $lage(),
    'nur Konten' => $lage(['konten_frei' => 3]),
    'Schicht ohne Besetzung' => $lage([
        'konten_frei' => 3, 'schicht' => $schicht,
    ]),
    'halb zugewiesen' => $lage([
        'konten_frei' => 3, 'schicht' => $schicht,
        'zugewiesen' => ['S2', 'Si'],
        'wartend' => [
            $wartet('S2', 'Sabine', 's2'),
            $wartet('Si', 'Simon', 'si'),
        ],
    ]),
    'ganz zugewiesen' => $lage([
        'konten_frei' => 5, 'schicht' => $schicht, 'zugewiesen' => $alle,
        'wartend' => array_map(
            static fn (string $f): array => $wartet($f, 'P', 'p'),
            $alle
        ),
    ]),
    'halb angenommen' => $lage([
        'konten_frei' => 5, 'schicht' => $schicht, 'zugewiesen' => $alle,
        'angenommen' => ['S2', 'Si', 'S6'],
        'wartend' => [
            $wartet('LdF', 'Lena', 'ldf'),
            $wartet('A/W', 'Arno', 'aw'),
        ],
    ]),
    'ganz angenommen' => $lage([
        'konten_frei' => 5, 'schicht' => $schicht,
        'zugewiesen' => $alle, 'angenommen' => $alle,
    ]),
    'aktiv' => $lage([
        'konten_frei' => 5, 'schicht' => $schicht, 'aktiv' => true,
        'je_aktiviert' => true,
        'zugewiesen' => $alle, 'angenommen' => $alle,
    ]),
    /*
     * Nach einer Einzelablösung im laufenden Betrieb: Die Nachbesetzung steht
     * auf ZUGEWIESEN, eine Pflichtfunktion ist also nicht angenommen. Die
     * Inbetriebnahme ist trotzdem vorbei -- sie war es in dem Augenblick, in
     * dem die Schicht aktiviert wurde.
     */
    'aktiv nach Einzelabloesung' => $lage([
        'konten_frei' => 5, 'schicht' => $schicht, 'aktiv' => true,
        'je_aktiviert' => true,
        'zugewiesen' => $alle,
        'angenommen' => ['S2', 'Si', 'S6', 'A/W'],
        'wartend' => [$wartet('LdF', 'Lena', 'ldf')],
    ]),
    /* Die erste Schicht lief und ist geschlossen; aktiviert wird nichts mehr. */
    'nach der ersten Schicht' => $lage([
        'konten_frei' => 5, 'je_aktiviert' => true,
    ]),
    // Widersprüchlich: angenommen ohne Schicht. Darf nicht durchfallen.
    'widersprüchlich' => $lage(['angenommen' => $alle]),
];
$erwartet = [
    'leer' => 1,
    'nur Konten' => 2,
    'Schicht ohne Besetzung' => 3,
    'halb zugewiesen' => 3,
    'ganz zugewiesen' => 4,
    'halb angenommen' => 4,
    'ganz angenommen' => 5,
    'aktiv' => 6,
    'aktiv nach Einzelabloesung' => 6,
    'nach der ersten Schicht' => 6,
    'widersprüchlich' => 1,
];

foreach ($lagen as $name => $einzelfall) {
    $schritte = estab_dv_shift_start_steps($einzelfall);

    $assert(
        count($schritte) === 6
            && array_column($schritte, 'nummer') === [1, 2, 3, 4, 5, 6],
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'In der Lage „' . $name . '" sind es nicht sechs lückenlos '
                . 'nummerierte Schritte.'
        )
    );

    $zustaende = array_column($schritte, 'zustand');
    $laufend = array_keys($zustaende, ESTAB_DV_SHIFT_START_CURRENT, true);
    $assert(
        count($laufend) === 1,
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'In der Lage „' . $name . '" ist nicht genau ein Schritt der '
                . 'aktuelle, sondern ' . count($laufend) . '.'
        )
    );
    $assert(
        $schritte[$laufend[0]]['nummer'] === $erwartet[$name],
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'In der Lage „' . $name . '" ist Schritt '
                . $schritte[$laufend[0]]['nummer'] . ' der aktuelle, '
                . 'erwartet war ' . $erwartet[$name] . '.'
        )
    );

    /*
     * Die zugesicherte Ordnung: erledigt, dann genau ein aktueller, dann
     * offen. Kein „erledigt" hinter dem aktuellen Schritt.
     *
     * Genau das brach vorher: Schritt 5 hing allein an der laufenden Schicht,
     * die Schritte 3 und 4 an ihrer gegenwärtigen Besetzung. Eine
     * Einzelablösung ergab „erledigt erledigt erledigt aktuell erledigt
     * offen" -- eine Liste, die sich als Rückschritt liest.
     */
    $erwarteteFolge = array_merge(
        array_fill(0, $laufend[0], ESTAB_DV_SHIFT_START_DONE),
        [ESTAB_DV_SHIFT_START_CURRENT],
        array_fill(0, 5 - $laufend[0], ESTAB_DV_SHIFT_START_PENDING)
    );
    $assert(
        $zustaende === $erwarteteFolge,
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'In der Lage „' . $name . '" steht ein erledigter Schritt hinter '
                . 'dem aktuellen: ' . implode(' ', $zustaende)
        )
    );

    foreach ($schritte as $schritt) {
        $assert(
            trim((string) $schritt['titel']) !== ''
                && trim((string) $schritt['satz']) !== ''
                && trim((string) $schritt['wer']) !== '',
            estab_ux_requirement(
                'UX-ABLAUFFUEHRUNG',
                'In der Lage „' . $name . '" nennt Schritt '
                    . $schritt['nummer'] . ' nicht Titel, Satz und Adressaten.'
            )
        );
    }

    /*
     * Ein Vorleseprogramm liest keine Farbe. `aria-current="step"` ist die
     * einzige Stelle, an der „hier stehen Sie" ohne Augen ankommt -- und es
     * darf genau einmal vorkommen.
     */
    $markup = estab_dv_shift_start_markup($schritte);
    $assert(
        substr_count($markup, 'aria-current="step"') === 1
            && substr_count($markup, '<li class="estab-ablauf-schritt') === 6,
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'In der Lage „' . $name . '" trägt das Markup nicht genau einen '
                . 'aktuellen Schritt bei sechs sichtbaren.'
        )
    );
}

// ---------------------------------------------------------------------------
// 2. Der erste Schritt ist keine Sackgasse: Grund und Weg stehen dabei.
// ---------------------------------------------------------------------------

$ohneKonten = estab_dv_shift_start_steps($lage())[0];
$assert(
    is_array($ohneKonten['ziel'])
        && $ohneKonten['ziel']['href'] === 'users.php'
        && str_contains($ohneKonten['satz'], 'Konto'),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Ohne Benutzerkonto nennt der Ablauf weder den Grund noch den Weg '
            . 'zur Benutzerverwaltung.'
    )
);
$assert(
    estab_dv_shift_start_steps($lage(['konten_frei' => 1]))[0]['ziel'] === null,
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Ein erledigter Schritt bietet weiterhin seinen Notausgang an.'
    )
);

// ---------------------------------------------------------------------------
// 3. Der Schritt, an dem die Inbetriebnahme scheiterte: die Annahme.
// ---------------------------------------------------------------------------

$annahme = estab_dv_shift_start_steps($lagen['halb angenommen'])[3];
$assert(
    str_contains($annahme['wer'], 'nicht die Administration')
        && str_contains($annahme['satz'], ESTAB_DV_SHIFT_START_PERSONAL_LABEL),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Der Annahmeschritt sagt nicht, wer ihn ausführt und wo.'
    )
);
/*
 * Namen statt Funktionskürzel. Wer eine Schicht in Betrieb nimmt, muss
 * jemanden ansprechen können -- „LdF fehlt noch" sagt nicht, wen man anruft.
 */
$assert(
    $annahme['namen'] === ['LdF · Lena (ldf)', 'Fernmelder · Arno (aw)'],
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Der Annahmeschritt benennt die Wartenden nicht mit Namen und '
            . 'Kürzel: ' . implode(' | ', $annahme['namen'])
    )
);
/*
 * Und er bietet keinen Verweis an. Er hatte einen -- auf die Annahmeseite.
 * Die Schichtverwaltung schützt der HTTP-Basiszugang, und der ist kein
 * eStab-Funktionskonto; die Annahmeseite verlangt genau ein solches. Der
 * Verweis führte seinen einzigen Leser gegen eine Anmeldewand.
 */
foreach ($lagen as $name => $einzelfall) {
    $assert(
        estab_dv_shift_start_steps($einzelfall)[3]['ziel'] === null,
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'In der Lage „' . $name . '" bietet der Annahmeschritt der '
                . 'Administration einen Verweis an, den ihr Zugang nicht '
                . 'öffnen kann.'
        )
    );
}
/*
 * Im laufenden Betrieb erzählt der Ablauf nicht mehr von Rückständen. Eine
 * Einzelablösung ist ein Vorgang der aktiven Schicht und steht in deren
 * Tafel, nicht in der Erzählung des Anfangs.
 */
$nachAbloesung = estab_dv_shift_start_steps(
    $lagen['aktiv nach Einzelabloesung']
);
$assert(
    $nachAbloesung[3]['namen'] === []
        && $nachAbloesung[4]['zustand'] === ESTAB_DV_SHIFT_START_DONE,
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Eine Einzelablösung im laufenden Betrieb wirft den Ablauf auf die '
            . 'Inbetriebnahme zurück.'
    )
);
/*
 * Und nach der ersten Schicht verspricht Schritt 5 keinen Knopf mehr, den
 * die Seite nicht mehr zeichnet.
 */
$assert(
    !str_contains(
        estab_dv_shift_start_steps($lagen['nach der ersten Schicht'])[4]['satz'],
        'unten'
    ),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Schritt 5 verweist weiterhin auf einen Aktivierungsknopf, den es '
            . 'nach der ersten Schicht nicht mehr gibt.'
    )
);

// ---------------------------------------------------------------------------
// 4. Pflichtbesetzung: eine Liste, nicht zwei.
// ---------------------------------------------------------------------------

$assert(
    estab_dv_shift_start_missing([]) === ESTAB_DV_REQUIRED_HATS
        && estab_dv_shift_start_missing($alle) === [],
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Ablaufführung führt eine eigene Pflichtliste statt '
            . 'ESTAB_DV_REQUIRED_HATS.'
    )
);
$besetzungsschritt = estab_dv_shift_start_steps(
    $lagen['Schicht ohne Besetzung']
)[2];
$assert(
    $besetzungsschritt['namen'] === array_map(
        'estab_function_display_name',
        ESTAB_DV_REQUIRED_HATS
    ),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Der Besetzungsschritt benennt nicht genau die fehlenden '
            . 'Pflichtfunktionen.'
    )
);
/*
 * A/W heißt in der Oberfläche „Fernmelder". Steht in der Ablaufführung das
 * Kürzel, sucht der Betrieb im Auswahlfeld nach einem Eintrag, den es dort
 * nicht gibt.
 */
$assert(
    in_array('Fernmelder', $besetzungsschritt['namen'], true)
        && !in_array('A/W', $besetzungsschritt['namen'], true),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Der Ablauf nennt A/W nicht so, wie die Oberfläche es anzeigt.'
    )
);

// ---------------------------------------------------------------------------
// 5. Die Faltung der Datenbanklisten -- auch ohne aktive Schicht.
// ---------------------------------------------------------------------------

$benutzer = [
    ['kuerzel' => 's2', 'benutzer' => 'Sabine', 'estab_gesperrt' => 0],
    ['kuerzel' => 'si', 'benutzer' => 'Simon', 'estab_gesperrt' => 0],
    ['kuerzel' => 'alt', 'benutzer' => 'Gesperrt', 'estab_gesperrt' => 1],
];
$besetzung = static fn (
    string $funktion,
    string $status,
    int $gesperrt = 0
): array => [
    'funktion' => $funktion,
    'status' => $status,
    'benutzer' => 'Person ' . $funktion,
    'benutzer_kuerzel' => strtolower($funktion),
    'benutzer_gesperrt' => $gesperrt,
];
/** Eine Zeile, wie estab_dv_shift_list() sie liefert. */
$schichtzeile = static fn (
    int $nummer,
    string $status,
    array $besetzungen = [],
    ?int $vorgaenger = null,
    ?string $aktiviert = null
): array => [
    'dienstschicht_id' => $nummer,
    'nummer' => $nummer,
    'bezeichnung' => 'Schicht ' . $nummer,
    'status' => $status,
    'vorgaenger_id' => $vorgaenger,
    'aktiviert_am' => $aktiviert,
    'besetzungen' => $besetzungen,
];

/*
 * Der Inbetriebnahmepfad: nur geplante Schichten, keine aktive. Er war
 * vorher unbelegt -- geprüft wurde ausgerechnet nur die Lage danach.
 *
 * estab_dv_shift_list() sortiert `nummer DESC`. Die Liste kommt also
 * absteigend an, und wer „die erste geplante" meint, aber „die erste in der
 * Liste" nimmt, bekommt die zuletzt angelegte. Genau das war der Fehler: Wer
 * neben der fertig besetzten Schicht #1 eine Schicht #2 anlegte, bekam die
 * Meldung, es sei nichts besetzt, während unten der Aktivierungsknopf von #1
 * stand.
 */
$zweiGeplante = estab_dv_shift_start_state($benutzer, [
    $schichtzeile(2, 'GEPLANT'),
    $schichtzeile(1, 'GEPLANT', [
        $besetzung('S2', 'ANGENOMMEN'),
        $besetzung('Si', 'ZUGEWIESEN'),
    ]),
]);
$assert(
    $zweiGeplante['schicht'] !== null
        && $zweiGeplante['schicht']['nummer'] === 1,
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Bei mehreren geplanten Schichten führt der Ablauf nicht die '
            . 'aktivierbare erste, sondern Schicht '
            . (string) ($zweiGeplante['schicht']['nummer'] ?? 0) . '.'
    )
);
$assert(
    $zweiGeplante['angenommen'] === ['S2']
        && $zweiGeplante['aktiv'] === false
        && $zweiGeplante['je_aktiviert'] === false,
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Faltung der geplanten Schicht stimmt nicht.'
    )
);

/*
 * Eine Nachfolgeschicht trägt einen Vorgänger und kann nie die erste sein.
 * Sie darf den Ablauf der Inbetriebnahme deshalb nicht an sich ziehen.
 */
$nurNachfolger = estab_dv_shift_start_state($benutzer, [
    $schichtzeile(2, 'GEPLANT', [$besetzung('S2', 'ZUGEWIESEN')], 1),
]);
$assert(
    $nurNachfolger['schicht'] === null && $nurNachfolger['zugewiesen'] === [],
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Eine geplante Nachfolgeschicht zieht den Ablauf der Inbetriebnahme '
            . 'an sich.'
    )
);

/* Die laufende Schicht setzt sich gegen die geplante durch. */
$zustand = estab_dv_shift_start_state($benutzer, [
    $schichtzeile(2, 'GEPLANT', [$besetzung('S2', 'ZUGEWIESEN')], 1),
    $schichtzeile(1, 'AKTIV', [
        $besetzung('S2', 'ANGENOMMEN'),
        $besetzung('Si', 'ZUGEWIESEN'),
        // Ein gesperrtes Konto trägt keine Funktion -- auch dann nicht,
        // wenn es die Annahme noch vor der Sperre erklärt hat.
        $besetzung('S6', 'ANGENOMMEN', 1),
    ], null, '2026-09-03 08:00:00'),
]);
$assert(
    $zustand['konten_frei'] === 2
        && $zustand['aktiv'] === true
        && $zustand['je_aktiviert'] === true
        && $zustand['schicht']['nummer'] === 1,
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die laufende Schicht setzt sich nicht gegen die geplante durch, '
            . 'oder ein gesperrtes Konto zählt als verfügbar.'
    )
);
$assert(
    $zustand['angenommen'] === ['S2']
        && $zustand['zugewiesen'] === ['S2', 'Si']
        && count($zustand['wartend']) === 1
        && $zustand['wartend'][0]['kuerzel'] === 'si',
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Besetzung eines gesperrten Kontos zählt mit, oder die wartende '
            . 'Annahme wird nicht als solche geführt.'
    )
);

/*
 * Eine geschlossene Schicht mit Aktivierungsstempel beendet die
 * Inbetriebnahme dauerhaft, auch wenn gerade nichts läuft.
 */
$geschlossen = estab_dv_shift_start_state($benutzer, [
    $schichtzeile(1, 'GESCHLOSSEN', [], null, '2026-09-03 08:00:00'),
]);
$assert(
    $geschlossen['je_aktiviert'] === true && $geschlossen['aktiv'] === false,
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Eine geschlossene, einst aktivierte Schicht gilt weiterhin als nie '
            . 'aktiviert.'
    )
);

/* Was aus der Datenbank kommt, wird maskiert. */
$boese = estab_dv_shift_start_markup(estab_dv_shift_start_steps($lage([
    'konten_frei' => 1,
    'schicht' => $schicht,
    'zugewiesen' => $alle,
    'wartend' => [$wartet('S2', '<script>alert(1)</script>', 's2')],
])));
$assert(
    !str_contains($boese, '<script>') && str_contains($boese, '&lt;script&gt;'),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Ablaufführung gibt einen Benutzernamen unmaskiert aus.'
    )
);

// ---------------------------------------------------------------------------
// 6. Die Seite benutzt den Ablauf und bietet keine Sackgasse mehr an.
// ---------------------------------------------------------------------------

$seite = $read('4fadm/fuehrungsstelle.php');
foreach ([
    'estab_dv_shift_start_state(',
    'estab_dv_shift_start_steps(',
    'estab_dv_shift_start_current(',
    'estab_dv_shift_start_markup(',
] as $aufruf) {
    $assert(
        str_contains($seite, $aufruf),
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'Die Schichtverwaltung ruft ' . $aufruf . ') nicht auf.'
        )
    );
}

$kontoauswahl = shift_start_kontoauswahlen($seite, [
    'if (!$hasAssignableAccount)' => 'sonst',
    'if ($availableUsers !== [])' => 'dann',
]);
$assert(
    $kontoauswahl['auswahlen'] >= 3,
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Seite hat weniger Kontoauswahlen als erwartet ('
            . $kontoauswahl['auswahlen'] . '); die Zuordnungsprüfung liefe '
            . 'ins Leere.'
    )
);
$assert(
    $kontoauswahl['ungeschuetzt'] === [],
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Diese Kontoauswahlen stehen in keinem Zweig, der ein verfügbares '
            . 'Konto festgestellt hat -- Zeile(n) '
            . implode(', ', $kontoauswahl['ungeschuetzt'])
    )
);
$assert(
    str_contains(
        $seite,
        '$hasAssignableAccount = $startState[\'konten_frei\'] > 0;'
    ),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Prüfung auf ein verfügbares Konto liest nicht aus der '
            . 'Ablauflage.'
    )
);
/*
 * Auch die Einzelablösung braucht jemanden, der übernimmt. Ein Pflichtfeld
 * „Übernehmende Person" ohne eine einzige Person ist derselbe Fehler an
 * anderer Stelle.
 */
$assert(
    str_contains($seite, '$nachfolger === []')
        && str_contains($seite, 'foreach ($nachfolger as $user)'),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Einzelablösung bietet ihr Formular auch ohne übernehmende '
            . 'Person an.'
    )
);

/*
 * Die Pflichtfunktionen stehen nicht als Prosa auf der Seite. Vorher stand
 * dort „Die Pflichtfunktionen S2, Si, S6, LdF und Fernmelder ..." -- eine
 * Abschrift, die beim nächsten Zuschnitt der Besetzung stehen bleibt,
 * während die Aktivierung längst etwas anderes verlangt.
 */
$assert(
    !str_contains($seite, 'S2, Si, S6, LdF und Fernmelder')
        && str_contains($seite, 'estab_dv_shift_start_function_text(')
        && str_contains($seite, 'ESTAB_DV_REQUIRED_HATS'),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Pflichtbesetzung steht als Prosa auf der Seite statt aus '
            . 'ESTAB_DV_REQUIRED_HATS zu kommen.'
    )
);

/* Die Funktionsauswahl gruppiert. */
$assert(
    str_contains($seite, 'optgroup label="Pflichtfunktionen der Schicht"')
        && str_contains($seite, 'optgroup label="Weitere Funktionen"'),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Funktionsauswahl trennt Pflichtfunktionen nicht von den übrigen.'
    )
);

/* Der Ort der persönlichen Annahme steht auf der Seite. */
$assert(
    str_contains($seite, 'ESTAB_DV_SHIFT_START_PERSONAL_LABEL'),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Schichtverwaltung nennt den Ort der persönlichen Annahme nicht.'
    )
);

/*
 * „Planung schließen" verwirft die Schicht. Es war der einzige Knopf, der im
 * blockierten Zustand noch sichtbar war -- und damit der wahrscheinlichste
 * Griff. Er steht jetzt hinter einer Aufklappung.
 *
 * Geprüft wird die Aufklappung selbst, nicht die Reihenfolge zweier
 * Zeichenketten: Ein `<div><p>Diese Planung verwerfen</p>` an derselben
 * Stelle hielt die frühere Zusicherung grün und stellte den Knopf wieder
 * offen hin.
 */
$ueberschrift = strpos($seite, '<summary>Diese Planung verwerfen</summary>');
$assert(
    is_int($ueberschrift),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Seite hat keine Aufklappung „Diese Planung verwerfen" mehr.'
    )
);
/*
 * Vom Titel aus nach aussen suchen, nicht von der ersten Aufklappung der
 * Datei aus: Der lockere Modus hat eine eigene, und die steht weiter oben.
 */
$verwerfen = strrpos(
    substr($seite, 0, (int) $ueberschrift),
    '<details class="estab-tool-details">'
);
$ende = strpos($seite, '</details>', (int) $ueberschrift);
$assert(
    is_int($verwerfen) && is_int($ende),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Überschrift „Diese Planung verwerfen" steht nicht in einer '
            . 'Aufklappung.'
    )
);
$abschnitt = substr($seite, (int) $verwerfen, (int) $ende - (int) $verwerfen);
$assert(
    str_contains($abschnitt, '<summary>Diese Planung verwerfen</summary>')
        && str_contains($abschnitt, 'value="close_duty_shift"')
        && str_contains($abschnitt, 'Planung schließen')
        && str_contains(
            $abschnitt,
            'ist <em>nicht</em> der Weg zur Inbetriebnahme'
        ),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Das Verwerfen der Planung steht nicht vollständig hinter der '
            . 'Aufklappung: Überschrift, Abgrenzung und Formular gehören '
            . 'zusammen dorthin.'
    )
);

/*
 * Der Planungskasten trägt in beiden Zweigen den richtigen Namen. Ein Test
 * auf das blosse Vorhandensein einer der beiden Überschriften bliebe grün,
 * wenn die Zweige vertauscht wären.
 */
$assert(
    str_contains($seite, "\$activeShift === null\n            ? 'Schritt 2: Dienstschicht planen'\n            : 'Nachfolgeschicht planen'"),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Der Planungskasten ordnet seine beiden Überschriften nicht '
            . 'nachweislich den richtigen Zweigen zu.'
    )
);

/*
 * Die Schrittnummern gehören der Inbetriebnahme. Eine geplante
 * Nachfolgeschicht wird übernommen, nicht aktiviert -- sie darf weder
 * „Schritt 3/4/5" tragen noch eine Aktivierung versprechen.
 */
$assert(
    str_contains($seite, '$istInbetriebnahme = $activeShift === null')
        && str_contains($seite, '&& !$hasActivationHistory')
        && str_contains($seite, "&& (\$shift['vorgaenger_id'] ?? null) === null"),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Seite unterscheidet die Inbetriebnahme nicht von einer '
            . 'geplanten Nachfolgeschicht.'
    )
);
foreach ([
    "\$istInbetriebnahme\n                ? 'Schritt 3: Funktion zuweisen'",
    "<?= \$istInbetriebnahme ? 'Schritt 4: ' : '' ?>",
    '<?php if ($istInbetriebnahme): ?>',
] as $stelle) {
    $assert(
        str_contains($seite, $stelle),
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'Eine Schrittnummer hängt nicht an der Inbetriebnahme: ' . $stelle
        )
    );
}
$assert(
    str_contains($seite, '<strong>Die Übergabe ist noch gesperrt.</strong>'),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Eine blockierte Nachfolgeschicht verspricht weiterhin eine '
            . 'Aktivierung statt einer Übergabe.'
    )
);

/*
 * Der Kasten über der Liste zitiert denselben Schritt, den die Liste
 * hervorhebt. Ein fester Satz daneben lief auseinander, sobald sich der
 * Zustand änderte.
 */
$assert(
    str_contains($seite, "estab_admin_html(\$startCurrent['titel'])")
        && str_contains($seite, "(int) \$startCurrent['nummer']")
        && str_contains($seite, "estab_admin_html(\$startCurrent['wer'])"),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Der Kasten über der Ablaufliste zitiert nicht den aktuellen '
            . 'Schritt, sondern behauptet einen eigenen Stand.'
    )
);

// ---------------------------------------------------------------------------
// 7. Das Stylesheet trägt die Zustände und ordnet den Zustandskasten.
// ---------------------------------------------------------------------------

$stil = $read('estab-ui.css');
foreach ([
    '.estab-ablauf',
    '.estab-ablauf-schritt',
    '.estab-ablauf-erledigt',
    '.estab-ablauf-aktuell',
    '.estab-ablauf-offen',
] as $auswaehler) {
    $assert(
        str_contains($stil, $auswaehler . ' ')
            || str_contains($stil, $auswaehler . ','),
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'Das Stylesheet kennt ' . $auswaehler . ' nicht; der aktuelle '
                . 'Schritt hebt sich dann nicht ab.'
        )
    );
}

/*
 * Der Zustandskasten sagt selbst, wer in welche Spalte kommt.
 *
 * Er ist als „links steht, was ist, rechts steht, was man tun kann" gebaut:
 * `minmax(0, 1fr) auto`. Welches Kind wohin kam, entschied aber allein die
 * Reihenfolge -- ein Kasten mit Überschrift *und* Erläuterung schob die
 * Erläuterung in die Handlungsspalte, deren `auto` sich nach dem längsten
 * Inhalt richtet, und drückte die Überschrift auf null Breite. Gemessen: auf
 * dieser Seite blieben „Unterstützter Betriebsmodus" bei 720 px genau 0 px.
 *
 * Handlung ist dabei nicht nur der Knopf: Die Zugangsschaltung des lockeren
 * Modus stellt ihrem gefährlichen Zweig eine Aufklappung voran. Fehlt
 * `details` in der Liste, steht derselbe Schalter je nach Zustand an zwei
 * verschiedenen Plätzen.
 */
$assert(
    str_contains($stil, '.estab-tool-status > :not(div) {'),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Der Zustandskasten ordnet seinen Text nicht namentlich der ersten '
            . 'Spalte zu; ein zweiter Textteil drückt dann die Überschrift '
            . 'auf null Breite.'
    )
);
$handlungsspalte = 0;
foreach (['', '    '] as $einzug) {
    $handlungsspalte += substr_count(
        $stil,
        $einzug . ".estab-tool-status > .estab-button,\n"
        . $einzug . ".estab-tool-status > details,\n"
        . $einzug . ".estab-tool-status > form {"
    );
}
$assert(
    $handlungsspalte === 2,
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Die Handlungsspalte nennt nicht in beiden Breiten dieselben drei '
            . 'Kinder (Knopf, Aufklappung, Formular), sondern '
            . $handlungsspalte . 'mal.'
    )
);
/*
 * Und unter 56 rem gibt es keine Handlungsspalte mehr. `grid-column: 2` legt
 * dort sonst eine zweite *implizite* Spalte an, die sich nach dem Knopf
 * richtet: gemessen blieben der Überschrift bei 360 px noch 35 von 302 px.
 */
$assert(
    str_contains($stil, '@media (max-width: 56rem) {')
        && str_contains(
            $stil,
            ".estab-tool-status:not(.estab-tool-status-summary) {\n"
            . '        grid-template-columns: minmax(0, 1fr);'
        ),
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Der Zustandskasten behält seine Handlungsspalte auch auf schmalen '
            . 'Schirmen, wo sie den Text auffrisst.'
    )
);

// ---------------------------------------------------------------------------
// 8. Gegenprobe: Beisst die Pruefung ueberhaupt?
// ---------------------------------------------------------------------------

$gegenprobe = estab_dv_shift_start_steps($lage([
    'konten_frei' => 5,
    'schicht' => $schicht,
    'zugewiesen' => $alle,
    'angenommen' => array_slice($alle, 0, 4),
]));
$assert(
    $gegenprobe[3]['zustand'] === ESTAB_DV_SHIFT_START_CURRENT
        && $gegenprobe[4]['zustand'] === ESTAB_DV_SHIFT_START_PENDING,
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Eine einzige fehlende Annahme gibt die Aktivierung bereits frei.'
    )
);
$assert(
    estab_dv_shift_start_current([]) === null,
    estab_ux_requirement(
        'UX-ABLAUFFUEHRUNG',
        'Eine leere Schrittliste meldet trotzdem einen aktuellen Schritt.'
    )
);
/*
 * Beisst die Zuordnungsprüfung der Kontoauswahlen? Drei gebaute Fälle: eine
 * Auswahl ohne jede Bedingung, eine im falschen Zweig des richtigen
 * Wächters, und eine im richtigen Zweig.
 */
$proben = [
    'ohne Waechter' => [
        '<select name="benutzer_kuerzel" required></select>',
        1,
    ],
    'falscher Zweig' => [
        '<?php if (!$hasAssignableAccount): ?>'
            . '<select name="benutzer_kuerzel" required></select>'
            . '<?php else: ?><?php endif; ?>',
        1,
    ],
    'richtiger Zweig' => [
        '<?php if (!$hasAssignableAccount): ?><?php else: ?>'
            . '<select name="benutzer_kuerzel" required></select>'
            . '<?php endif; ?>',
        0,
    ],
];
foreach ($proben as $probeName => [$quelle, $erwarteteBefunde]) {
    $ergebnis = shift_start_kontoauswahlen($quelle, [
        'if (!$hasAssignableAccount)' => 'sonst',
        'if ($availableUsers !== [])' => 'dann',
    ]);
    $assert(
        count($ergebnis['ungeschuetzt']) === $erwarteteBefunde,
        estab_ux_requirement(
            'UX-ABLAUFFUEHRUNG',
            'Die Zuordnungsprüfung urteilt über den Fall „' . $probeName
                . '" falsch: ' . count($ergebnis['ungeschuetzt'])
                . ' statt ' . $erwarteteBefunde . ' Befunde.'
        )
    );
}

printf(
    "dv_shift_start_flow: %d Zusicherungen erfüllt.\n",
    $assertions
);
