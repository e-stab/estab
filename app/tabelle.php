<?php

declare(strict_types=1);

/**
 * Ein Bauteil für alle Listen der Anwendung.
 *
 * Jede Tabelle des eStab war für sich gebaut. Der Nachweisung fehlte die
 * Suche, „Stab lesen" blätterte ohne Wirkung, Anhänge und Benutzerliste sahen
 * anders aus -- und wer einen Eintrag suchte, musste bei jeder Tabelle neu
 * überlegen, wie das hier geht. Sechs Rückmeldungen aus dem Betrieb, eine
 * Ursache.
 *
 * Eine Seite sagt hier nur noch, **was** in der Tabelle steht. Sortieren,
 * Filtern, Blättern, Zählen, Markup schreiben und den Zustand in der Adresse
 * führen übernimmt das Bauteil. Wer das je Tabelle noch einmal schreibt, baut
 * die nächste Abweichung ein.
 *
 * Der Vertrag steht in docs/TABELLEN.md, das Aussehen in docs/GESTALTUNG.md
 * Abschnitt 6 und 7.
 *
 * ## Alles auf dem Server
 *
 * Sortieren, Suchen, Filtern und Blättern geschehen über ein Formular mit
 * `method="get"`. Ohne Skript bleibt die Tabelle vollständig bedienbar, und
 * der Zustand steht in der Adresse: Eine sortierte, gefilterte Liste lässt
 * sich weitergeben und als Lesezeichen ablegen. Ein Skript darf dasselbe
 * schneller tun -- nichts anderes.
 *
 * ## Warum die Sortierung hier liegt und nicht in der Seite
 *
 * Sortiert wird nach der **Art** der Spalte. Eine Vorrangstufe alphabetisch
 * zu sortieren stellt „Blitz" vor „Staatsnot" und macht die Spalte wertlos;
 * eine Nachweisnummer als Zeichenkette stellt 10 vor 9. Beides ist in einer
 * Führungsstelle kein Schönheitsfehler, und beides passiert genau einmal,
 * wenn die Regel an einer Stelle steht.
 */

require_once __DIR__ . '/message_repository.php';
require_once __DIR__ . '/tabelle_felder.php';

/** Wie viele Zeilen eine Seite fasst, wenn die Seite nichts anderes sagt. */
const ESTAB_TABELLE_SEITENGROESSE = 25;

/** Die wählbaren Seitengrößen. */
const ESTAB_TABELLE_SEITENGROESSEN = [25, 50, 100];

/**
 * Die Dringlichkeit einer Vorrangstufe als Zahl.
 *
 * Größer ist dringender. Die Stufen kommen aus dem Nachrichtenvordruck
 * (Feld 9); „ohne" ist keine Stufe, sondern ihre Abwesenheit, und steht
 * deshalb unten.
 */
function estab_tabelle_vorrang_rang(string $wert): int
{
    $wert = mb_strtolower(trim($wert));
    if ($wert === '') {
        return 0;
    }
    foreach ([
        3 => ['aaa', 'staatsnot'],
        2 => ['bbb', 'blitz'],
        1 => ['sss', 'sofort'],
    ] as $rang => $namen) {
        foreach ($namen as $name) {
            if ($wert === $name || str_contains($wert, $name)) {
                return $rang;
            }
        }
    }
    return 0;
}

/**
 * Eine Zeichenkette auf ihre Sortierform bringen.
 *
 * Deutsche Sortierfolge ohne die Erweiterung intl: Umlaute zählen wie ihre
 * Umschreibung, Groß- und Kleinschreibung sind gleich. Das ist nicht DIN
 * 5007-2, aber es stellt „Ärger" neben „Aerger" statt hinter „Zypern" -- und
 * das ist der Fehler, der in einer Namensliste auffällt.
 */
function estab_tabelle_sortierform(string $wert): string
{
    $wert = mb_strtolower(trim($wert));
    return strtr($wert, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'á' => 'a', 'à' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
    ]);
}

/**
 * Der Zeitpunkt hinter einer Zeitangabe, oder null.
 *
 * Der Vordruck schreibt Zeiten als taktische Zeitgruppe (TThhmmMMMyyyy); die
 * Datenbank liefert sie als Zeitstempel. Beide müssen zu demselben Zeitpunkt
 * führen, sonst sortiert dieselbe Spalte je nach Herkunft anders.
 */
function estab_tabelle_zeitpunkt(string $wert): ?int
{
    $wert = trim($wert);
    if ($wert === '') {
        return null;
    }
    if (preg_match(
        '~\A(\d{2})(\d{2})(\d{2})([[:alpha:]]{3})(\d{4})\z~u',
        $wert,
        $teile
    ) === 1) {
        $monate = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'mär' => 3, 'apr' => 4,
            'may' => 5, 'mai' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
            'sep' => 9, 'oct' => 10, 'okt' => 10, 'nov' => 11,
            'dec' => 12, 'dez' => 12,
        ];
        $monat = $monate[mb_strtolower($teile[4])] ?? null;
        if ($monat !== null) {
            return (int) mktime(
                (int) $teile[2],
                (int) $teile[3],
                0,
                $monat,
                (int) $teile[1],
                (int) $teile[5]
            );
        }
    }
    $zeitpunkt = strtotime($wert);
    return $zeitpunkt === false ? null : $zeitpunkt;
}

/**
 * Die erste Zahl in einer Angabe, oder null.
 *
 * „TBB 1079" und „1079" sind dieselbe Nummer. Wer die Spalte als Zeichenkette
 * sortiert, bekommt 10 vor 9.
 */
function estab_tabelle_zahlwert(string $wert): ?float
{
    if (preg_match('~-?\d+(?:[.,]\d+)?~', $wert, $treffer) !== 1) {
        return null;
    }
    return (float) str_replace(',', '.', $treffer[0]);
}

/**
 * Zwei Werte einer Spalte vergleichen -- nach ihrer Art.
 *
 * Leere Angaben stehen immer am Ende, gleich in welche Richtung sortiert
 * wird. Eine leere Zelle ist keine Angabe, und eine Liste, die bei
 * absteigender Sortierung mit lauter Leerzeilen beginnt, verbirgt genau das,
 * wonach jemand sortiert hat.
 */
function estab_tabelle_vergleichen(
    string $art,
    string $links,
    string $rechts,
    string $richtung = 'auf'
): int {
    // Die Leerregel gilt vor der Richtung und wird nicht mit ihr gedreht.
    // Wer sie hinter das Vorzeichen legt, bekommt bei absteigender
    // Sortierung eine Liste, die mit lauter Leerzeilen beginnt.
    $linksLeer = trim($links) === '';
    $rechtsLeer = trim($rechts) === '';
    if ($linksLeer || $rechtsLeer) {
        return $linksLeer && $rechtsLeer ? 0 : ($linksLeer ? 1 : -1);
    }
    $ergebnis = match ($art) {
        'zahl' => estab_tabelle_zahlwert($links) <=> estab_tabelle_zahlwert($rechts),
        'zeit' => estab_tabelle_zeitpunkt($links) <=> estab_tabelle_zeitpunkt($rechts),
        'vorrang' => estab_tabelle_vorrang_rang($links)
            <=> estab_tabelle_vorrang_rang($rechts),
        default => estab_tabelle_sortierform($links)
            <=> estab_tabelle_sortierform($rechts),
    };
    return $richtung === 'ab' ? -$ergebnis : $ergebnis;
}

/**
 * Eine Spalte auf ihre vollständige Form bringen.
 *
 * @param array<string,mixed> $spalte
 * @return array<string,mixed>
 */
function estab_tabelle_spalte(array $spalte): array
{
    return [
        'schluessel' => (string) ($spalte['schluessel'] ?? ''),
        'kopf' => (string) ($spalte['kopf'] ?? ''),
        'breite' => (int) ($spalte['breite'] ?? 0),
        'sortierbar' => (bool) ($spalte['sortierbar'] ?? false),
        'suchbar' => (bool) ($spalte['suchbar'] ?? false),
        'art' => (string) ($spalte['art'] ?? 'text'),
        'zahlenspalte' => in_array(
            (string) ($spalte['art'] ?? 'text'),
            ['zahl', 'zeit'],
            true
        ),
        'filter' => array_values(array_map(
            'strval',
            (array) ($spalte['filter'] ?? [])
        )),
        'filtername' => (string) ($spalte['filtername'] ?? ''),
        // Eine Zelle, die ein Bedienelement traegt, kann keine Zeichenkette
        // sein. Die Seite baut sie dann selbst -- und maskiert dabei selbst,
        // was sie einsetzt. Gesiebt und sortiert wird trotzdem ueber den
        // Wert der Spalte, nicht ueber ihr Markup: Sonst suchte man in
        // Klassennamen statt in Angaben.
        'zelle' => $spalte['zelle'] ?? null,
        // Eine selbstgebaute Zelle kann trotzdem auf zwei Zeilen geklammert
        // werden. Eine Zeile, die zehn Zeilen hoch ist, macht die Liste
        // unbrauchbar -- und der ganze Text steht im Vordruck, den ein Klick
        // oeffnet.
        'klammern' => (bool) ($spalte['klammern'] ?? false),
    ];
}

/**
 * Den Zustand einer Tabelle aus der Adresse lesen.
 *
 * @param list<array<string,mixed>> $spalten
 * @param array<string,mixed> $quelle
 * @return array<string,mixed>
 */
function estab_tabelle_zustand(
    string $id,
    array $spalten,
    array $quelle,
    array $eigeneFelder = [],
    array $groessen = []
): array {
    $felder = estab_tabelle_felder($id, $eigeneFelder);
    // Eine Seite darf ihre eigenen Seitengroessen mitbringen -- die
    // Meldungsuebersicht kennt seit jeher 25, 50 und 100.
    $groessen = $groessen === [] ? ESTAB_TABELLE_SEITENGROESSEN : $groessen;
    $lies = static function (array $quelle, string $name): string {
        $wert = $quelle[$name] ?? '';
        return is_string($wert) ? trim($wert) : '';
    };

    $sortierbar = [];
    foreach ($spalten as $spalte) {
        if ($spalte['sortierbar']) {
            $sortierbar[] = $spalte['schluessel'];
        }
    }
    $sortierung = $lies($quelle, $felder['sortierung']);
    if (!in_array($sortierung, $sortierbar, true)) {
        $sortierung = '';
    }
    $richtung = $lies($quelle, $felder['richtung']) === 'ab' ? 'ab' : 'auf';

    $groesse = (int) $lies($quelle, $felder['groesse']);
    if (!in_array($groesse, $groessen, true)) {
        $groesse = in_array(ESTAB_TABELLE_SEITENGROESSE, $groessen, true)
            ? ESTAB_TABELLE_SEITENGROESSE
            : (int) $groessen[0];
    }
    $felder['groessenliste'] = implode(',', array_map('strval', $groessen));
    $seite = max(1, (int) $lies($quelle, $felder['seite']));

    $spaltensuche = [];
    $filter = [];
    foreach ($spalten as $spalte) {
        if ($spalte['suchbar']) {
            $wert = $lies($quelle, $felder['spalte'] . $spalte['schluessel']);
            if ($wert !== '') {
                $spaltensuche[$spalte['schluessel']] = $wert;
            }
        }
        if ($spalte['filter'] !== []) {
            $wert = $lies($quelle, $felder['filter'] . $spalte['schluessel']);
            if ($wert !== '' && in_array($wert, $spalte['filter'], true)) {
                $filter[$spalte['schluessel']] = $wert;
            }
        }
    }

    return [
        'felder' => $felder,
        'sortierung' => $sortierung,
        'richtung' => $richtung,
        'seite' => $seite,
        'groesse' => $groesse,
        'suche' => $lies($quelle, $felder['suche']),
        'spaltensuche' => $spaltensuche,
        'filter' => $filter,
    ];
}

/**
 * Die Zeilen nach dem Zustand aussieben.
 *
 * @param list<array<string,string>> $zeilen
 * @param list<array<string,mixed>> $spalten
 * @param array<string,mixed> $zustand
 * @return list<array<string,string>>
 */
function estab_tabelle_sieben(array $zeilen, array $spalten, array $zustand): array
{
    $suchbar = [];
    foreach ($spalten as $spalte) {
        if ($spalte['suchbar']) {
            $suchbar[] = $spalte['schluessel'];
        }
    }
    $enthaelt = static fn (string $heu, string $nadel): bool =>
        $nadel === '' || mb_stripos($heu, $nadel) !== false;

    $behalten = [];
    foreach ($zeilen as $zeile) {
        // Volltext: über alle durchsuchbaren Spalten, nicht über die Zeile.
        // Wer nach "offen" sucht, meint den Bearbeitungsstand und nicht ein
        // Wort, das zufällig in einer verborgenen Spalte steht.
        if ($zustand['suche'] !== '') {
            $treffer = false;
            foreach ($suchbar as $schluessel) {
                if ($enthaelt((string) ($zeile[$schluessel] ?? ''), $zustand['suche'])) {
                    $treffer = true;
                    break;
                }
            }
            if (!$treffer) {
                continue;
            }
        }
        // Spaltensuchen wirken zusammen, nicht alternativ.
        foreach ($zustand['spaltensuche'] as $schluessel => $nadel) {
            if (!$enthaelt((string) ($zeile[$schluessel] ?? ''), $nadel)) {
                continue 2;
            }
        }
        foreach ($zustand['filter'] as $schluessel => $wert) {
            if ((string) ($zeile[$schluessel] ?? '') !== $wert) {
                continue 2;
            }
        }
        $behalten[] = $zeile;
    }
    return $behalten;
}

/**
 * Die Zeilen sortieren -- nach der Art der Spalte.
 *
 * Ohne gewählte Sortierung bleibt die Grundordnung der Seite stehen. Die ist
 * eine Aussage: Eine Meldungsliste kommt nach Vorrang und Zeit, und wer nicht
 * sortiert hat, will genau das sehen.
 *
 * @param list<array<string,string>> $zeilen
 * @param list<array<string,mixed>> $spalten
 * @param array<string,mixed> $zustand
 * @return list<array<string,string>>
 */
function estab_tabelle_sortieren(array $zeilen, array $spalten, array $zustand): array
{
    if ($zustand['sortierung'] === '') {
        return $zeilen;
    }
    $gewaehlt = null;
    foreach ($spalten as $spalte) {
        if ($spalte['schluessel'] === $zustand['sortierung']) {
            $gewaehlt = $spalte;
            break;
        }
    }
    if ($gewaehlt === null) {
        return $zeilen;
    }
    // Eine stabile Sortierung: Bei gleichem Wert bleibt die Grundordnung der
    // Seite erhalten. usort() in PHP ist seit 8.0 stabil.
    $richtung = $zustand['richtung'];
    usort(
        $zeilen,
        static fn (array $a, array $b): int => estab_tabelle_vergleichen(
            $gewaehlt['art'],
            (string) ($a[$gewaehlt['schluessel']] ?? ''),
            (string) ($b[$gewaehlt['schluessel']] ?? ''),
            $richtung
        )
    );
    return $zeilen;
}

/**
 * Die Adresse für einen geänderten Zustand.
 *
 * @param array<string,string> $aenderung
 */
function estab_tabelle_adresse(array $zustand, array $aenderung): string
{
    $quelle = $_GET;
    foreach ($aenderung as $name => $wert) {
        if ($wert === '') {
            unset($quelle[$name]);
        } else {
            $quelle[$name] = $wert;
        }
    }
    // Jede Änderung an Sortierung oder Sieb führt auf die erste Seite. Sonst
    // landet man auf Seite 7 einer Liste, die noch drei Seiten hat, und sieht
    // eine leere Tabelle.
    if (!array_key_exists($zustand['felder']['seite'], $aenderung)) {
        unset($quelle[$zustand['felder']['seite']]);
    }
    $abfrage = http_build_query($quelle);
    return $abfrage === '' ? '?' : '?' . $abfrage;
}

/**
 * Eine fertig ausgegebene Tabellenzeile in ihre Zellen zerlegen.
 *
 * Manche Listen des Bestandes bauen ihre Zellen in Verzweigungen auf: Ein und
 * dieselbe Spalte wird an vier Stellen ausgegeben, je nachdem, ob eine
 * Nachricht gelesen, erledigt, dringend oder nichts davon ist. Aus dem
 * Quelltext lässt sich dann nicht ablesen, welches `<td>` zu welcher Spalte
 * gehört -- aus dem Ergebnis schon: Es sind genau so viele wie Spalten.
 *
 * Stimmt die Zahl nicht, bricht das hier ab. Eine Zeile, deren Zellen
 * verrutscht sind, zeigt die Angaben der falschen Spalte -- und im
 * Meldewesen ist das schlimmer als eine Fehlermeldung.
 *
 * @return list<string>
 */
function estab_tabelle_zeile_zerlegen(string $ausgabe, int $spalten): array
{
    if (preg_match_all('~<td\b[^>]*>(.*?)</td>~s', $ausgabe, $treffer) === false) {
        throw new RuntimeException('Die Tabellenzeile ist nicht lesbar.');
    }
    $zellen = $treffer[1];
    if (count($zellen) !== $spalten) {
        throw new RuntimeException(
            'Die Tabellenzeile hat ' . count($zellen) . ' Zellen statt '
                . $spalten . '. Eine Zeile mit verrutschten Zellen zeigt die '
                . 'Angaben der falschen Spalte.'
        );
    }
    return $zellen;
}

/** Ab wieviel Zeichen eine Zelle einen Aufklapp bekommt. */
const ESTAB_TABELLE_KLAMMER = 160;

/**
 * Der Inhalt einer Zelle: gekürzt auf zwei Zeilen, mit Aufklapp für den Rest.
 *
 * Text in einer Zelle steht auf höchstens zwei Zeilen. Eine Zeile, die zehn
 * Zeilen hoch ist, macht die Liste unbrauchbar. Der Rest ist deshalb nicht
 * verloren, sondern eingeklappt -- in derselben Zelle, ohne Skript.
 *
 * Das Kürzen gehört ins Bauteil und nicht in die Seite. Eine Seite, die
 * fertiges Markup liefert, umgeht die Maskierung; eine, die selbst kürzt,
 * kürzt beim nächsten Mal anders.
 */
function estab_tabelle_zelleninhalt(string $wert): string
{
    $wert = trim($wert);
    if ($wert === '') {
        return '<span class="estab-tabelle-klammer">–</span>';
    }
    if (mb_strlen($wert) <= ESTAB_TABELLE_KLAMMER) {
        return '<span class="estab-tabelle-klammer">'
            . estab_message_html($wert) . '</span>';
    }
    $anfang = mb_substr($wert, 0, ESTAB_TABELLE_KLAMMER);
    // An der letzten Wortgrenze trennen, nicht mitten im Wort.
    $luecke = mb_strrpos($anfang, ' ');
    if ($luecke !== false && $luecke > ESTAB_TABELLE_KLAMMER - 30) {
        $anfang = mb_substr($anfang, 0, $luecke);
    }
    return '<span class="estab-tabelle-klammer">'
        . estab_message_html(rtrim($anfang)) . ' …</span>'
        . '<details class="estab-tabelle-mehr"><summary>Ganzer Text</summary>'
        . '<p>' . estab_message_html($wert) . '</p></details>';
}

/** Ein Knopf, der eine Zeile öffnet. */
function estab_tabelle_knopf(string $beschriftung, string $ziel, array $felder): string
{
    $markup = '<form class="estab-tabelle-zellenform" method="get" action="'
        . estab_message_html($ziel) . '">';
    foreach ($felder as $name => $wert) {
        $markup .= '<input type="hidden" name="' . estab_message_html((string) $name)
            . '" value="' . estab_message_html((string) $wert) . '">';
    }
    return $markup . '<button type="submit" class="estab-button">'
        . estab_message_html($beschriftung) . '</button></form>';
}

/**
 * Der Kopf einer Spalte -- als Knopf, wenn nach ihr sortiert werden kann.
 *
 * Der Knopf trägt den Namen der Spalte und daneben ein Zeichen für den
 * Zustand. Daneben, nicht statt seiner: Ein ⇅ allein sagt nicht, wonach
 * sortiert würde.
 *
 * @param array<string,mixed> $spalte
 * @param array<string,mixed> $zustand
 */
function estab_tabelle_kopfzelle(array $spalte, array $zustand): string
{
    $breite = $spalte['breite'] > 0
        ? ' style="width:' . $spalte['breite'] . '%"'
        : '';
    if (!$spalte['sortierbar']) {
        return '<th scope="col"' . $breite . '>'
            . estab_message_html($spalte['kopf']) . '</th>';
    }
    $aktiv = $zustand['sortierung'] === $spalte['schluessel'];
    $auf = $aktiv && $zustand['richtung'] === 'auf';
    $ab = $aktiv && $zustand['richtung'] === 'ab';
    // Ein Klick sortiert aufsteigend, der nächste absteigend, der dritte hebt
    // die Sortierung auf und stellt die Grundordnung der Seite wieder her.
    if (!$aktiv) {
        $naechste = [$spalte['schluessel'], 'auf'];
    } elseif ($auf) {
        $naechste = [$spalte['schluessel'], 'ab'];
    } else {
        $naechste = ['', ''];
    }
    $adresse = estab_tabelle_adresse($zustand, [
        $zustand['felder']['sortierung'] => $naechste[0],
        $zustand['felder']['richtung'] => $naechste[1],
    ]);
    $stand = $auf ? 'ascending' : ($ab ? 'descending' : 'none');
    $zeichen = $auf ? '▲' : ($ab ? '▼' : '⇅');
    $sagt = $auf
        ? 'aufsteigend sortiert, jetzt absteigend sortieren'
        : ($ab
            ? 'absteigend sortiert, jetzt Sortierung aufheben'
            : 'nicht sortiert, jetzt aufsteigend sortieren');
    return '<th scope="col" aria-sort="' . $stand . '"' . $breite . '>'
        . '<a class="estab-tabelle-sortknopf" href="'
        . estab_message_html($adresse) . '" title="'
        . estab_message_html($spalte['kopf'] . ': ' . $sagt) . '">'
        . '<span>' . estab_message_html($spalte['kopf']) . '</span>'
        . '<span class="estab-tabelle-sortzeichen" aria-hidden="true">'
        . $zeichen . '</span>'
        . '<span class="estab-visually-hidden">' . estab_message_html($sagt)
        . '</span></a></th>';
}

/**
 * Der Blätterer.
 *
 * Nicht verfügbare Griffe bleiben stehen und werden gesperrt. Eine Leiste,
 * die ihre Breite ändert, verschiebt den nächsten Griff unter den Zeiger --
 * und wer zweimal auf „weiter" klickt, landet woanders als erwartet.
 *
 * @param array<string,mixed> $zustand
 */
function estab_tabelle_blaetterer(array $zustand, int $seiten): string
{
    if ($seiten <= 1) {
        return '';
    }
    $jetzt = $zustand['seite'];
    $griff = static function (
        string $beschriftung,
        int $ziel,
        bool $moeglich,
        array $zustand
    ): string {
        if (!$moeglich) {
            return '<span class="estab-tabelle-griff estab-tabelle-griff--aus" '
                . 'aria-disabled="true">' . estab_message_html($beschriftung)
                . '</span>';
        }
        return '<a class="estab-tabelle-griff" href="' . estab_message_html(
            estab_tabelle_adresse($zustand, [
                $zustand['felder']['seite'] => (string) $ziel,
            ])
        ) . '">' . estab_message_html($beschriftung) . '</a>';
    };

    $markup = '<nav class="estab-tabelle-blaetterer" aria-label="Blättern">'
        . $griff('◀', $jetzt - 1, $jetzt > 1, $zustand);
    // Ein Fenster um die aktuelle Seite: Bei 40 Seiten ist eine Leiste mit
    // vierzig Griffen keine Hilfe.
    $von = max(1, min($jetzt - 2, $seiten - 4));
    $bis = min($seiten, max($jetzt + 2, 5));
    for ($seite = $von; $seite <= $bis; $seite++) {
        if ($seite === $jetzt) {
            $markup .= '<span class="estab-tabelle-griff '
                . 'estab-tabelle-griff--hier" aria-current="page">'
                . $seite . '</span>';
            continue;
        }
        $markup .= $griff((string) $seite, $seite, true, $zustand);
    }
    return $markup . $griff('▶', $jetzt + 1, $jetzt < $seiten, $zustand)
        . '</nav>';
}

/**
 * Die verborgenen Felder, die den übrigen Zustand mitführen.
 *
 * Ein Suchformular, das die Sortierung vergisst, wirft sie bei jeder Suche
 * weg -- und der Bediener merkt es erst an der Reihenfolge.
 *
 * @param array<string,mixed> $zustand
 */
function estab_tabelle_mitfuehren(array $zustand, array $ausser = []): string
{
    $markup = '';
    foreach ($_GET as $name => $wert) {
        if (!is_string($wert) || !is_string($name)) {
            continue;
        }
        if (in_array($name, $ausser, true)) {
            continue;
        }
        if (str_starts_with($name, $zustand['felder']['spalte'])
            || str_starts_with($name, $zustand['felder']['filter'])) {
            continue;
        }
        if (in_array($name, [
            $zustand['felder']['suche'],
            $zustand['felder']['seite'],
            $zustand['felder']['groesse'],
        ], true)) {
            continue;
        }
        $markup .= '<input type="hidden" name="' . estab_message_html($name)
            . '" value="' . estab_message_html($wert) . '">';
    }
    return $markup;
}

/**
 * Die Suchbänder über der Tabelle.
 *
 * @param list<array<string,mixed>> $spalten
 * @param array<string,mixed> $zustand
 */
function estab_tabelle_suchband(
    array $spalten,
    array $zustand,
    string $zusatz = ''
): string {
    $suchbar = [];
    foreach ($spalten as $spalte) {
        if ($spalte['suchbar']) {
            $suchbar[] = $spalte['kopf'];
        }
    }
    // Die Spaltenmasken stehen in der Tabelle, gehoeren aber zu diesem
    // Formular. Ein form-Verweis verbindet sie, ohne dass ein Formular eine
    // Tabelle umschliessen muss -- das ergaebe ungueltiges Markup.
    $markup = '<form id="estab-tabelle-sieb-'
        . estab_message_html($zustand['felder']['suche'])
        . '" class="estab-tabelle-suchband" method="get" role="search">'
        . estab_tabelle_mitfuehren($zustand)
        . '<div class="estab-tabelle-suchzeile">'
        . '<label class="estab-tabelle-feld">'
        . '<span>Suchen</span>'
        . '<input type="search" name="'
        . estab_message_html($zustand['felder']['suche'])
        . '" value="' . estab_message_html($zustand['suche']) . '"></label>';

    foreach ($spalten as $spalte) {
        if ($spalte['filter'] === []) {
            continue;
        }
        $markup .= '<label class="estab-tabelle-feld"><span>'
            . estab_message_html($spalte['kopf']) . '</span><select name="'
            . estab_message_html($zustand['felder']['filter'] . $spalte['schluessel'])
            . '">';
        // Der erste Eintrag ist eine Alle-Stufe mit Namen. Ein „—" sagt
        // nicht, was es aufhebt.
        $alle = $spalte['filtername'] !== ''
            ? $spalte['filtername']
            : 'Alle ' . $spalte['kopf'];
        $markup .= '<option value="">' . estab_message_html($alle) . '</option>';
        foreach ($spalte['filter'] as $wert) {
            $gewaehlt = ($zustand['filter'][$spalte['schluessel']] ?? '') === $wert
                ? ' selected' : '';
            $markup .= '<option value="' . estab_message_html($wert) . '"'
                . $gewaehlt . '>' . estab_message_html($wert) . '</option>';
        }
        $markup .= '</select></label>';
    }

    /*
     * Was die Seite selbst mitbringt, steht *im* Band -- nicht daneben.
     *
     * Manche Listen haben Bedienelemente, die nur sie haben: die
     * Kategorienauswahl der Meldungsliste, die Schalter fuer gelesen und
     * erledigt. Ohne diese Stelle muessten sie ihre eigene Leiste
     * danebenstellen, und dann sieht wieder jede Tabelle anders aus.
     */
    $markup .= $zusatz;

    $markup .= '<label class="estab-tabelle-feld"><span>Zeilen</span><select name="'
        . estab_message_html($zustand['felder']['groesse']) . '">';
    $angebot = array_map(
        'intval',
        explode(',', (string) ($zustand['felder']['groessenliste'] ?? ''))
    );
    foreach (($angebot === [0] ? ESTAB_TABELLE_SEITENGROESSEN : $angebot) as $groesse) {
        $markup .= '<option value="' . $groesse . '"'
            . ($zustand['groesse'] === $groesse ? ' selected' : '') . '>'
            . $groesse . '</option>';
    }
    $markup .= '</select></label>'
        . '<button type="submit" class="estab-button estab-button-primary">'
        . 'Anwenden</button></div>';

    if ($suchbar !== []) {
        // Ohne diesen Satz ist eine leere Trefferliste nicht deutbar: Der
        // Bediener weiss nicht, ob sein Wort nirgends steht oder nur nicht
        // dort gesucht wurde.
        $markup .= '<p class="estab-tabelle-suchhinweis">Gesucht wird in: '
            . estab_message_html(implode(', ', $suchbar)) . '.</p>';
    }
    return $markup . '</form>';
}

/**
 * Die Zeile der Spaltenmasken, direkt unter dem Kopf.
 *
 * @param list<array<string,mixed>> $spalten
 * @param array<string,mixed> $zustand
 */
function estab_tabelle_maskenzeile(array $spalten, array $zustand, bool $mitAktion): string
{
    $markup = '<tr class="estab-tabelle-masken">';
    foreach ($spalten as $spalte) {
        if (!$spalte['suchbar']) {
            $markup .= '<td></td>';
            continue;
        }
        $name = $zustand['felder']['spalte'] . $spalte['schluessel'];
        $markup .= '<td><input type="search" form="estab-tabelle-sieb-'
            . estab_message_html($zustand['felder']['suche'])
            . '" name="' . estab_message_html($name)
            . '" value="' . estab_message_html(
                $zustand['spaltensuche'][$spalte['schluessel']] ?? ''
            ) . '" aria-label="' . estab_message_html($spalte['kopf'] . ' durchsuchen')
            . '"></td>';
    }
    if ($mitAktion) {
        $markup .= '<td></td>';
    }
    return $markup . '</tr>';
}

/**
 * Die Ergebnisleiste: wie viele Treffer, wonach sortiert, welche Filter.
 *
 * @param array<string,mixed> $zustand
 * @param list<array<string,mixed>> $spalten
 */
function estab_tabelle_ergebnisleiste(
    array $zustand,
    array $spalten,
    int $treffer,
    int $gesamt
): string {
    $sortiert = 'Grundordnung der Seite';
    foreach ($spalten as $spalte) {
        if ($spalte['schluessel'] === $zustand['sortierung']) {
            $sortiert = $spalte['kopf'] . ', '
                . ($zustand['richtung'] === 'ab' ? 'absteigend' : 'aufsteigend');
        }
    }
    $marken = '';
    $entfernen = static fn (array $zustand, string $feld): string =>
        estab_message_html(estab_tabelle_adresse($zustand, [$feld => '']));
    if ($zustand['suche'] !== '') {
        $marken .= '<a class="estab-tabelle-marke" href="'
            . $entfernen($zustand, $zustand['felder']['suche'])
            . '">Suche: ' . estab_message_html($zustand['suche'])
            . ' <span aria-hidden="true">×</span>'
            . '<span class="estab-visually-hidden">entfernen</span></a>';
    }
    foreach ($spalten as $spalte) {
        foreach ([
            ['spaltensuche', 'spalte', $spalte['kopf']],
            ['filter', 'filter', $spalte['kopf']],
        ] as [$topf, $praefix, $name]) {
            $wert = $zustand[$topf][$spalte['schluessel']] ?? '';
            if ($wert === '') {
                continue;
            }
            $marken .= '<a class="estab-tabelle-marke" href="'
                . $entfernen(
                    $zustand,
                    $zustand['felder'][$praefix] . $spalte['schluessel']
                )
                . '">' . estab_message_html($name . ': ' . $wert)
                . ' <span aria-hidden="true">×</span>'
                . '<span class="estab-visually-hidden">entfernen</span></a>';
        }
    }
    return '<div class="estab-tabelle-ergebnisleiste">'
        . '<p class="estab-tabelle-treffer" role="status">'
        . $treffer . ' von ' . $gesamt . ' '
        . ($gesamt === 1 ? 'Eintrag' : 'Einträgen')
        . ' · Sortierung: ' . estab_message_html($sortiert) . '</p>'
        . ($marken === '' ? '' : '<div class="estab-tabelle-marken">'
            . $marken . '</div>')
        . '</div>';
}

/**
 * Eine Tabelle ausgeben.
 *
 * @param array<string,mixed> $tabelle
 */
function estab_tabelle_ausgeben(array $tabelle): void
{
    echo estab_tabelle_markup($tabelle);
}

/**
 * Das vollständige Markup einer Tabelle.
 *
 * Getrennt vom Ausgeben, damit eine Prüfung sie bauen kann, ohne eine Seite
 * aufzurufen -- und damit eine Seite sie in einen eigenen Rahmen setzen kann.
 *
 * @param array<string,mixed> $tabelle
 */
function estab_tabelle_markup(array $tabelle): string
{
    $id = (string) ($tabelle['id'] ?? 'tabelle');
    $spalten = array_map(
        'estab_tabelle_spalte',
        array_values((array) ($tabelle['spalten'] ?? []))
    );
    $alle = array_values((array) ($tabelle['zeilen'] ?? []));
    $aktion = $tabelle['aktion'] ?? null;
    $leer = (string) ($tabelle['leer'] ?? 'Kein Eintrag entspricht den gesetzten Filtern.');
    $quelle = (array) ($tabelle['quelle'] ?? $_GET);

    /*
     * Zwei Betriebsarten.
     *
     * Im Regelfall siebt, sortiert und blättert das Bauteil selbst über die
     * übergebenen Zeilen. Das ist der einfachere und der häufigere Fall.
     *
     * Trifft die Seite ihre Auswahl selbst -- weil sie in der Datenbank
     * siebt und dabei die Berechtigungsprüfung mitführt --, gibt sie unter
     * `fremd` an, was ihre Auswahl ergeben hat: wie viele Zeilen sie gefunden
     * hat, wie viele es insgesamt gibt und auf welcher Seite von wievielen
     * sie steht. Das Bauteil stellt dann nur dar.
     *
     * Der Preis dieser zweiten Betriebsart ist bekannt und gewollt: Eine
     * Seite, die selbst siebt, bringt auch ihre eigenen Suchbänder mit. Sie
     * schaltet die des Bauteils mit `baender => false` ab, und die
     * Adressfelder ihrer bestehenden Bedienung reicht sie unter `felder`
     * herein -- so muss sie ihre Adressen nicht ändern, und der Blätterer des
     * Bauteils spricht trotzdem ihre Sprache.
     */
    $fremd = $tabelle['fremd'] ?? null;
    $zustand = estab_tabelle_zustand(
        $id,
        $spalten,
        $quelle,
        (array) ($tabelle['felder'] ?? []),
        array_map('intval', (array) ($tabelle['groessen'] ?? []))
    );

    if (is_array($fremd)) {
        $sichtbar = $alle;
        $treffer = (int) ($fremd['treffer'] ?? count($alle));
        $gesamt = (int) ($fremd['gesamt'] ?? $treffer);
        $seiten = max(1, (int) ($fremd['seiten'] ?? 1));
        $zustand['seite'] = max(1, min((int) ($fremd['seite'] ?? 1), $seiten));
    } else {
        $gesiebt = estab_tabelle_sieben($alle, $spalten, $zustand);
        $gesiebt = estab_tabelle_sortieren($gesiebt, $spalten, $zustand);
        $treffer = count($gesiebt);
        $gesamt = count($alle);
        $seiten = max(1, (int) ceil($treffer / $zustand['groesse']));
        $zustand['seite'] = min($zustand['seite'], $seiten);
        $sichtbar = array_slice(
            $gesiebt,
            ($zustand['seite'] - 1) * $zustand['groesse'],
            $zustand['groesse']
        );
    }

    $eigeneBaender = ($tabelle['baender'] ?? true) === false;

    /*
     * Eine Liste, die nichts kann, sieht aus wie eine, die alles kann.
     *
     * Das Bauteil zeichnet ein Suchband, eine Ergebnisleiste und einen
     * Blätterer -- gleichgültig, ob die Spalten dahinter etwas hergeben.
     * Eine Seite, die lauter unsortierbare und undurchsuchbare Spalten
     * übergibt, bekäme eine Tabelle mit einem Suchfeld, das nie etwas
     * findet, und mit Köpfen, die auf Klick nichts tun. Das ist schlimmer
     * als eine schlichte Tabelle: Es verspricht eine Bedienung, die es
     * nicht gibt.
     *
     * Deshalb wird hier abgewiesen statt still gezeichnet. Damit ist
     * jede eingesetzte Tabelle gegen dieselbe Zusicherung gehalten -- auch
     * die, die es noch nicht gibt (SPEC.md R5.5).
     *
     * Nicht für Tafeln ohne Bänder: Eine Statustafel mit vier festen
     * Zeilen zeigt einen Stand, keine Liste. Sortierung und Suche wären
     * dort Beiwerk.
     */
    /*
     * Und nicht für Tabellen, die ihre Auswahl selbst treffen.
     *
     * Die Meldungsübersicht sortiert und siebt in der Datenbank über
     * *alle* Seiten und reicht dem Bauteil nur die fertige Seite herein
     * (`fremd`). Ihre Spalten sind deshalb absichtlich nicht sortierbar --
     * eine Sortierung über fünfzig angezeigte Zeilen wäre eine andere
     * Sortierung als die über zwölfhundert. Sie bringt ihre Bedienung
     * unter `zusatzbaender` mit.
     *
     * Ohne diese Ausnahme hätte die Regel die wichtigste Liste der
     * Anwendung abgewiesen. Gefunden beim Nachzählen, bevor sie lief.
     */
    if (!$eigeneBaender && !is_array($fremd)) {
        $sortierbareSpalten = 0;
        $suchbareSpalten = 0;
        foreach ($spalten as $spalte) {
            if ($spalte['sortierbar']) {
                $sortierbareSpalten++;
            }
            if ($spalte['suchbar']) {
                $suchbareSpalten++;
            }
        }
        if ($sortierbareSpalten === 0) {
            throw new InvalidArgumentException(
                'Die Tabelle "' . $id . '" hat keine einzige sortierbare '
                    . 'Spalte. Ihre Spaltenköpfe sähen aus wie Knöpfe und '
                    . 'täten nichts. Entweder eine Spalte wird sortierbar, '
                    . 'oder die Tabelle trägt "baender" => false und zeigt '
                    . 'einen festen Stand.'
            );
        }
        if ($suchbareSpalten === 0) {
            throw new InvalidArgumentException(
                'Die Tabelle "' . $id . '" hat keine einzige durchsuchbare '
                    . 'Spalte. Das Suchfeld darüber fände nie etwas. '
                    . 'Entweder eine Spalte wird durchsuchbar, oder die '
                    . 'Tabelle trägt "baender" => false.'
            );
        }
    }
    // Eine schmale Tabelle mit wenigen Spalten wird erst spaeter zu Karten.
    $markup = '<section class="estab-tabelle'
        . (($tabelle['schmal'] ?? false) === true
            ? ' estab-tabelle--schmal'
            : '')
        . '" data-estab-tabelle="'
        . estab_message_html($id) . '">'
        . ($eigeneBaender
            ? ''
            : estab_tabelle_suchband(
                $spalten,
                $zustand,
                (string) ($tabelle['zusatzbaender'] ?? '')
            ))
        . ($eigeneBaender
            ? ''
            : estab_tabelle_ergebnisleiste($zustand, $spalten, $treffer, $gesamt));

    if ($sichtbar === []) {
        // Der Leerzustand nennt den Grund und bietet den Weg zurück an. Eine
        // leere Fläche ohne beides sieht aus wie ein Fehler.
        $zuruecksetzen = [$zustand['felder']['suche'] => ''];
        foreach (array_keys($zustand['spaltensuche']) as $schluessel) {
            $zuruecksetzen[$zustand['felder']['spalte'] . $schluessel] = '';
        }
        foreach (array_keys($zustand['filter']) as $schluessel) {
            $zuruecksetzen[$zustand['felder']['filter'] . $schluessel] = '';
        }
        if ($eigeneBaender) {
            // Ohne eigene Bänder gibt es hier auch keinen Weg zurück, den
            // das Bauteil kennt -- die Seite hat ihren eigenen Leerzustand.
            return $markup . '<div class="estab-tabelle-leer"><p>'
                . estab_message_html($leer) . '</p></div></section>';
        }
        return $markup . '<div class="estab-tabelle-leer"><p>'
            . estab_message_html($leer) . '</p>'
            . '<a class="estab-button" href="'
            . estab_message_html(estab_tabelle_adresse($zustand, $zuruecksetzen))
            . '">Filter zurücksetzen</a></div></section>';
    }

    /*
     * Die Beschriftung nennt einem Vorleseprogramm, was diese Tabelle ist,
     * bevor es die erste Zelle vorliest. Ohne sie heisst es nur "Tabelle mit
     * sieben Spalten" -- und auf einer Seite mit zwei Tabellen weiss niemand,
     * welche gemeint ist. Sie steht unsichtbar, weil die Ueberschrift der
     * Seite dasselbe schon sagt.
     */
    $beschriftung = (string) ($tabelle['beschriftung'] ?? '');
    /*
     * Die Mindestbreite gehoert zur Tabelle, nicht zum Bauteil: Eine Liste
     * mit drei Spalten braucht eine andere als eine mit acht. Ist sie zu
     * klein, quetscht `table-layout: fixed` eine Spalte auf ein Zeichen je
     * Zeile; ist sie zu gross, scrollt der Rahmen ohne Not.
     */
    $mindestbreite = (string) ($tabelle['mindestbreite'] ?? '');
    $markup .= '<div class="estab-tabelle-rahmen"><table class="estab-tabelle-blatt"'
        . ($mindestbreite === ''
            ? ''
            : ' style="min-width:' . estab_message_html($mindestbreite) . '"')
        . '>'
        . ($beschriftung === ''
            ? ''
            : '<caption class="estab-visually-hidden">'
                . estab_message_html($beschriftung) . '</caption>')
        . '<thead><tr>';
    foreach ($spalten as $spalte) {
        $markup .= estab_tabelle_kopfzelle($spalte, $zustand);
    }
    if ($aktion !== null) {
        $markup .= '<th scope="col" style="width:8%">Aktion</th>';
    }
    $markup .= '</tr>'
        . ($eigeneBaender
            ? ''
            : estab_tabelle_maskenzeile($spalten, $zustand, $aktion !== null))
        . '</thead><tbody>';

    $zeilenmarke = $tabelle['zeilenmarke'] ?? null;
    foreach ($sichtbar as $zeile) {
        // Eine Zeile darf eine eigene Marke tragen -- die Durchschriftenfarbe
        // etwa. Sie kommt als fertiges Attribut von der Seite, weil nur die
        // Seite weiss, was ihre Zeilen bedeuten.
        $markup .= '<tr'
            . ($zeilenmarke !== null ? ' ' . $zeilenmarke($zeile) : '')
            . '>';
        foreach ($spalten as $spalte) {
            // data-label traegt die Kopfbezeichnung mit in die Zelle. Unter
            // 48rem Spaltenbreite wird die Tabelle zu Karten, und eine Karte
            // ohne Bezeichnungen ist eine Reihe nackter Werte.
            if ($spalte['zelle'] !== null) {
                $inhalt = ($spalte['zelle'])($zeile);
                if ($spalte['klammern']) {
                    $inhalt = '<span class="estab-tabelle-klammer">'
                        . $inhalt . '</span>';
                }
            } else {
                $inhalt = estab_tabelle_zelleninhalt(
                    (string) ($zeile[$spalte['schluessel']] ?? '')
                );
            }
            $markup .= '<td data-label="' . estab_message_html($spalte['kopf']) . '"'
                . ($spalte['zahlenspalte'] ? ' class="estab-tabelle-zahl"' : '')
                . '>' . $inhalt . '</td>';
        }
        if ($aktion !== null) {
            $markup .= '<td data-label="Aktion">' . $aktion($zeile) . '</td>';
        }
        $markup .= '</tr>';
    }

    // Wer seine eigenen Baender mitbringt, blaettert auch selbst: Zwei
    // Blaetterer nebeneinander, die verschiedene Adressen ansprechen, sind
    // schlimmer als einer.
    return $markup . '</tbody></table></div>'
        . ($eigeneBaender ? '' : estab_tabelle_blaetterer($zustand, $seiten))
        . '</section>';
}
