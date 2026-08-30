<?php

declare(strict_types=1);

/**
 * Das Tabellenbauteil, an seinem Verhalten gemessen.
 *
 * Jede Tabelle der Anwendung war für sich gebaut, und das Ergebnis waren
 * sechs Rückmeldungen aus dem Betrieb mit einer Ursache. Dieses Bauteil hat
 * eine Aufrufstelle -- und deshalb kann eine Prüfung es an seinem Verhalten
 * fassen statt an seiner Erscheinung.
 *
 * Geprüft wird darum nicht, ob der Quelltext bestimmte Zeilen enthält,
 * sondern was das Bauteil aus gegebenen Zeilen macht: In welcher Reihenfolge
 * sie herauskommen, welche das Sieb passieren, was auf Seite zwei steht.
 *
 * Der wichtigste Fall ist die Vorrangstufe. Alphabetisch sortiert steht
 * „Blitz" vor „Staatsnot", und die Spalte ist wertlos -- schlimmer als keine,
 * weil sie so aussieht, als stimme sie.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once $root . '/app/tabelle.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Die Spalten, an denen das Bauteil geprüft wird. */
$spalten = [
    ['schluessel' => 'nummer', 'kopf' => 'Nachweis', 'breite' => 12,
        'sortierbar' => true, 'suchbar' => true, 'art' => 'zahl'],
    ['schluessel' => 'zeit', 'kopf' => 'Zeit', 'breite' => 14,
        'sortierbar' => true, 'suchbar' => false, 'art' => 'zeit'],
    ['schluessel' => 'vorrang', 'kopf' => 'Vorrang', 'breite' => 12,
        'sortierbar' => true, 'suchbar' => false, 'art' => 'vorrang',
        'filter' => ['Sofort', 'Blitz', 'Staatsnot'],
        'filtername' => 'Alle Vorrangstufen'],
    ['schluessel' => 'inhalt', 'kopf' => 'Inhalt', 'breite' => 50,
        'sortierbar' => true, 'suchbar' => true, 'art' => 'text'],
];

$zeilen = [
    ['nummer' => '9', 'zeit' => '281200aug2026', 'vorrang' => 'Sofort',
        'inhalt' => 'Ölspur auf der B57'],
    ['nummer' => '10', 'zeit' => '280800aug2026', 'vorrang' => 'Staatsnot',
        'inhalt' => 'Ausfall der Stromversorgung'],
    ['nummer' => '107', 'zeit' => '2026-08-28 16:00:00', 'vorrang' => 'Blitz',
        'inhalt' => 'Übergabe an den Abschnitt Nord'],
    ['nummer' => '2', 'zeit' => '', 'vorrang' => '',
        'inhalt' => 'Ärger mit der Gegenstelle'],
];

/** Die Schlüssel der sichtbaren Zeilen, in ihrer Reihenfolge. */
$reihenfolge = static function (array $quelle) use ($spalten, $zeilen): array {
    $markup = estab_tabelle_markup([
        'id' => 'probe',
        'spalten' => $spalten,
        'zeilen' => $zeilen,
        'quelle' => $quelle,
    ]);
    preg_match_all(
        '~<tbody>(.*?)</tbody>~s',
        $markup,
        $rumpf
    );
    if (($rumpf[1] ?? []) === []) {
        return [];
    }
    preg_match_all(
        '~<td[^>]*><span class="estab-tabelle-klammer">([^<]*)</span>~',
        $rumpf[1][0],
        $zellen
    );
    $treffer = [];
    // Die erste Zelle jeder Zeile ist die Nummer -- vier Spalten je Zeile.
    foreach ($zellen[1] as $stelle => $wert) {
        if ($stelle % 4 === 0) {
            $treffer[] = $wert;
        }
    }
    return $treffer;
};

$felder = estab_tabelle_felder('probe');

/* --- Sortierung nach der Art, nicht nach dem Text --- */

$assert(
    $reihenfolge([]) === ['9', '10', '107', '2'],
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Ohne gewählte Sortierung bleibt die Grundordnung der Seite nicht '
            . 'stehen: ' . implode(', ', $reihenfolge([]))
    )
);

$zahlen = $reihenfolge([$felder['sortierung'] => 'nummer', $felder['richtung'] => 'auf']);
$assert(
    $zahlen === ['2', '9', '10', '107'],
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Eine Zahlenspalte sortiert als Zeichenkette -- 10 käme vor 9: '
            . implode(', ', $zahlen)
    )
);
$assert(
    $reihenfolge([$felder['sortierung'] => 'nummer', $felder['richtung'] => 'ab'])
        === ['107', '10', '9', '2'],
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Absteigend sortiert die Zahlenspalte nicht umgekehrt.'
    )
);

// Der Fall, um dessentwillen die Sortierung im Bauteil liegt.
$vorrang = $reihenfolge([$felder['sortierung'] => 'vorrang', $felder['richtung'] => 'ab']);
$assert(
    $vorrang === ['10', '107', '9', '2'],
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Vorrangstufen sortieren nicht nach Dringlichkeit. Alphabetisch '
            . 'stünde Blitz vor Staatsnot, und die Spalte wäre wertlos: '
            . implode(', ', $vorrang)
    )
);

$zeit = $reihenfolge([$felder['sortierung'] => 'zeit', $felder['richtung'] => 'auf']);
$assert(
    $zeit === ['10', '9', '107', '2'],
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Zeiten sortieren nicht nach Zeitpunkt. Die taktische Zeitgruppe und '
            . 'der Datenbankzeitstempel müssen dieselbe Ordnung ergeben: '
            . implode(', ', $zeit)
    )
);

// Leere Angaben stehen in beiden Richtungen am Ende.
foreach (['auf', 'ab'] as $richtung) {
    $ordnung = $reihenfolge([$felder['sortierung'] => 'zeit', $felder['richtung'] => $richtung]);
    $assert(
        end($ordnung) === '2',
        estab_ux_requirement(
            'GES-TABELLE-SORTIERUNG',
            'Die Zeile ohne Zeitangabe steht bei Richtung ' . $richtung
                . ' nicht am Ende. Eine Liste, die mit Leerzeilen beginnt, '
                . 'verbirgt genau das, wonach jemand sortiert hat.'
        )
    );
}

// Und die Textspalte nach deutscher Folge: Ärger neben Ausfall, nicht ganz
// hinten.
$text = $reihenfolge([$felder['sortierung'] => 'inhalt', $felder['richtung'] => 'auf']);
$assert(
    $text[0] === '2',
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        '„Ärger" sortiert nicht wie „Aerger": ' . implode(', ', $text)
    )
);

/* --- Suche --- */

$assert(
    $reihenfolge([$felder['suche'] => 'abschnitt']) === ['107'],
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Die Volltextsuche findet nicht über alle durchsuchbaren Spalten.'
    )
);
$assert(
    $reihenfolge([$felder['suche'] => 'ABSCHNITT']) === ['107'],
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Die Suche unterscheidet Gross- und Kleinschreibung.'
    )
);
// Eine nicht durchsuchbare Spalte wird nicht durchsucht: Wer "Blitz" sucht,
// meint den Inhalt, nicht die Vorrangspalte -- dafür ist der Filter da.
$assert(
    $reihenfolge([$felder['suche'] => 'Blitz']) === [],
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Die Volltextsuche greift auf Spalten zu, die nicht als suchbar '
            . 'benannt sind. Der Satz „Gesucht wird in…" stimmt dann nicht.'
    )
);
$assert(
    $reihenfolge([$felder['spalte'] . 'nummer' => '10']) === ['10', '107'],
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Die Spaltensuche wirkt nicht auf ihre Spalte.'
    )
);
// Mehrere Spaltensuchen wirken zusammen, nicht alternativ.
$assert(
    $reihenfolge([
        $felder['spalte'] . 'nummer' => '10',
        $felder['spalte'] . 'inhalt' => 'Nord',
    ]) === ['107'],
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Zwei Spaltensuchen wirken nicht zusammen.'
    )
);
$assert(
    $reihenfolge([$felder['filter'] . 'vorrang' => 'Blitz']) === ['107'],
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Der Filter einer Spalte mit begrenztem Wertevorrat greift nicht.'
    )
);
// Ein Filterwert, den es nicht gibt, wird verworfen statt angewandt.
$assert(
    $reihenfolge([$felder['filter'] . 'vorrang' => 'Erfunden']) === ['9', '10', '107', '2'],
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Ein erfundener Filterwert leert die Tabelle, statt verworfen zu '
            . 'werden.'
    )
);

$markup = estab_tabelle_markup([
    'id' => 'probe', 'spalten' => $spalten, 'zeilen' => $zeilen, 'quelle' => [],
]);
$assert(
    str_contains($markup, 'Gesucht wird in: Nachweis, Inhalt.'),
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Der Satz nennt nicht, worin gesucht wird. Ohne ihn ist eine leere '
            . 'Trefferliste nicht deutbar.'
    )
);
$assert(
    str_contains($markup, '<option value="">Alle Vorrangstufen</option>'),
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Die Alle-Stufe eines Filters hat keinen Namen. Ein „—" sagt nicht, '
            . 'was es aufhebt.'
    )
);

/* --- Blättern --- */

$viele = [];
for ($nummer = 1; $nummer <= 60; $nummer++) {
    $viele[] = ['nummer' => (string) $nummer, 'zeit' => '', 'vorrang' => '',
        'inhalt' => 'Zeile ' . $nummer];
}
$blaettern = static function (array $quelle) use ($spalten, $viele): string {
    return estab_tabelle_markup([
        'id' => 'probe', 'spalten' => $spalten, 'zeilen' => $viele,
        'quelle' => $quelle,
    ]);
};
$erste = $blaettern([]);

/*
 * Wohlgeformtheit der Zeilen. Die Prüfung stand früher an den
 * Zeilenbausteinen der Meldungsübersicht, die von Hand "<tr>" und "<td>"
 * aneinanderreihten; seit das Bauteil die Zeilen baut, gehört sie
 * hierher. Sie zählt nicht nur Zellen -- das tut die Prüfung darüber --
 * sondern hält den Aufbau nach: keine Zelle außerhalb einer Zeile,
 * keine Zeile in einer Zeile, keine offene Marke am Ende. Ein
 * unbedachtes "zelle" könnte all das brechen.
 */
$wohlgeformt = static function (string $markup) use ($assert): int {
    preg_match_all('~</?(?:tr|td|th)\b[^>]*>~i', $markup, $treffer);
    $stapel = [];
    $zellen = 0;
    $verletzungen = 0;
    foreach ($treffer[0] as $marke) {
        $schliessend = str_starts_with($marke, '</');
        preg_match('~^</?([a-z]+)~i', $marke, $name);
        $name = strtolower($name[1] ?? '');
        if (!$schliessend) {
            if ($name === 'tr' && $stapel !== []) { $verletzungen++; }
            if ($name !== 'tr') {
                if (end($stapel) !== 'tr') { $verletzungen++; }
                $zellen++;
            }
            $stapel[] = $name;
            continue;
        }
        if (array_pop($stapel) !== $name) { $verletzungen++; }
    }
    if ($stapel !== []) { $verletzungen++; }
    $assert(
        $verletzungen === 0,
        estab_ux_requirement(
            'GES-TABELLE-EINHEITLICH',
            'Das Bauteil setzt ' . $verletzungen . ' Zellen oder Zeilen '
                . 'ineinander oder lässt Marken offen.'
        )
    );
    return $zellen;
};
/*
 * Drei Sorten Zeilen tragen je Spalte genau eine Zelle: die Kopfzeile,
 * die Zeile der Spaltenmasken und die 25 Datenzeilen der Seite.
 */
$erwarteteZellen = (1 + 1 + 25) * 4;
$assert(
    $wohlgeformt($erste) === $erwarteteZellen,
    estab_ux_requirement(
        'GES-TABELLE-EINHEITLICH',
        'Kopf, Suchmasken und Datenzeilen tragen zusammen nicht '
            . $erwarteteZellen . ' Zellen, sondern ' . $wohlgeformt($erste)
            . '. Eine Spalte fällt in einer der drei Zeilenarten aus.'
    )
);
$assert(
    substr_count($erste, 'estab-tabelle-klammer') === 25 * 4,
    estab_ux_requirement(
        'GES-TABELLE-BLAETTERN',
        'Eine Seite fasst nicht die vorgesehene Zahl von Zeilen, sondern '
            . (substr_count($erste, 'estab-tabelle-klammer') / 4) . '.'
    )
);
$assert(
    str_contains($erste, 'aria-current="page"'),
    estab_ux_requirement(
        'GES-TABELLE-BLAETTERN',
        'Die aktuelle Seite ist nicht als solche ausgezeichnet.'
    )
);
$assert(
    str_contains($erste, 'aria-disabled="true"'),
    estab_ux_requirement(
        'GES-TABELLE-BLAETTERN',
        'Auf der ersten Seite ist der Griff nach hinten nicht gesperrt, '
            . 'sondern weggelassen. Eine Leiste, die ihre Breite ändert, '
            . 'verschiebt den nächsten Griff unter den Zeiger.'
    )
);
$zweite = $blaettern([$felder['seite'] => '2']);
$assert(
    str_contains($zweite, '>Zeile 26<') && !str_contains($zweite, '>Zeile 25<'),
    estab_ux_requirement(
        'GES-TABELLE-BLAETTERN',
        'Die zweite Seite zeigt nicht die zweiten fünfundzwanzig Zeilen. '
            . 'Genau das war der Befund an „Stab lesen".'
    )
);
// Eine Seitenzahl jenseits des Endes führt auf die letzte Seite, nicht in
// eine leere Tabelle.
$jenseits = $blaettern([$felder['seite'] => '99']);
$assert(
    str_contains($jenseits, '>Zeile 60<'),
    estab_ux_requirement(
        'GES-TABELLE-BLAETTERN',
        'Eine Seitenzahl jenseits des Endes zeigt eine leere Tabelle statt '
            . 'der letzten Seite.'
    )
);
$grosse = $blaettern([$felder['groesse'] => '50']);
$assert(
    substr_count($grosse, 'estab-tabelle-klammer') === 50 * 4,
    estab_ux_requirement(
        'GES-TABELLE-BLAETTERN',
        'Die Seitengröße lässt sich nicht wählen.'
    )
);

/* --- Ohne Skript --- */

$assert(
    substr_count($markup, 'method="get"') >= 1
        && !str_contains($markup, 'onclick')
        && !str_contains($markup, 'method="post"'),
    estab_ux_requirement(
        'GES-TABELLE-OHNE-SKRIPT',
        'Das Sieb läuft nicht über ein GET-Formular. Der Zustand stünde '
            . 'dann nicht in der Adresse und liesse sich nicht weitergeben.'
    )
);
// Sortieren und Blättern sind Verweise, keine Skriptaufrufe.
$assert(
    preg_match_all('~<a class="estab-tabelle-sortknopf" href="\?~', $markup) === 4,
    estab_ux_requirement(
        'GES-TABELLE-OHNE-SKRIPT',
        'Nicht jede sortierbare Spalte trägt einen Verweis, der ohne Skript '
            . 'wirkt.'
    )
);
$assert(
    str_contains($erste, '<a class="estab-tabelle-griff" href="?'),
    estab_ux_requirement(
        'GES-TABELLE-OHNE-SKRIPT',
        'Der Blätterer wirkt nicht ohne Skript.'
    )
);

/* --- Zwei Tabellen auf einer Seite stören einander nicht --- */

$eins = estab_tabelle_felder('nachweisung-eingang');
$zwei = estab_tabelle_felder('nachweisung-ausgang');
$assert(
    $eins['seite'] !== $zwei['seite'] && $eins['suche'] !== $zwei['suche'],
    estab_ux_requirement(
        'GES-TABELLE-BLAETTERN',
        'Zwei Tabellen auf einer Seite teilen sich ihre Adressfelder und '
            . 'blättern gemeinsam.'
    )
);

/* --- Lange Texte werden gekürzt, nicht abgeschnitten --- */

/*
 * Text in einer Zelle steht auf höchstens zwei Zeilen -- der Rest ist
 * eingeklappt, nicht verloren. Das gehört ins Bauteil: Eine Seite, die
 * fertiges Markup liefert, umgeht die Maskierung, und eine, die selbst
 * kürzt, kürzt beim nächsten Mal anders.
 */
$lang = str_repeat('Lagemeldung Abschnitt Nord ', 20);
$mitLangtext = estab_tabelle_markup([
    'id' => 'probe', 'spalten' => $spalten,
    'zeilen' => [['nummer' => '1', 'zeit' => '', 'vorrang' => '', 'inhalt' => $lang]],
    'quelle' => [],
]);
$assert(
    str_contains($mitLangtext, '<details class="estab-tabelle-mehr">')
        && str_contains($mitLangtext, 'Ganzer Text'),
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Ein langer Text bekommt keinen Aufklapp; der Rest wäre verloren.'
    )
);
$assert(
    str_contains($mitLangtext, ' …</span>'),
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Der gekürzte Anfang ist nicht als gekürzt erkennbar.'
    )
);

/*
 * Und die Maskierung bleibt beim Bauteil. Eine Seite, die Auszeichnung in
 * eine Zelle gibt, bekommt sie als Text zu sehen -- nicht als Auszeichnung.
 */
$boese = estab_tabelle_markup([
    'id' => 'probe', 'spalten' => $spalten,
    'zeilen' => [[
        'nummer' => '1', 'zeit' => '', 'vorrang' => '',
        'inhalt' => '<img src=x onerror=alert(1)>',
    ]],
    'quelle' => [],
]);
$assert(
    !str_contains($boese, '<img src=x')
        && str_contains($boese, '&lt;img src=x'),
    'Das Bauteil gibt Auszeichnung aus einer Zelle unmaskiert weiter.'
);

/* --- Zellen mit Bedienelementen --- */

/*
 * Eine Zelle, die einen Knopf trägt, kann keine Zeichenkette sein. Die Seite
 * baut sie dann selbst. Gesiebt und sortiert wird trotzdem über den **Wert**
 * der Spalte, nicht über ihr Markup -- sonst suchte man in Klassennamen
 * statt in Angaben, und eine Suche nach „button" fände jede Zeile.
 */
$mitKnopf = $spalten;
$mitKnopf[3]['zelle'] = static fn (array $z): string =>
    '<button type="button" class="estab-erledigt">'
        . estab_message_html($z['inhalt']) . '</button>';
$gebaut = estab_tabelle_markup([
    'id' => 'probe', 'spalten' => $mitKnopf, 'zeilen' => $zeilen,
    'quelle' => [$felder['suche'] => 'Abschnitt'],
    'zeilenmarke' => static fn (array $z): string =>
        'data-lfd="' . estab_message_html($z['nummer']) . '"',
]);
$assert(
    substr_count($gebaut, '<button type="button" class="estab-erledigt">') === 1,
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Eine Spalte mit eigener Zelle wird nicht ausgegeben.'
    )
);
$assert(
    str_contains($gebaut, 'data-lfd="107"'),
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Eine Zeile kann keine eigene Marke tragen.'
    )
);
$assert(
    estab_tabelle_markup([
        'id' => 'probe', 'spalten' => $mitKnopf, 'zeilen' => $zeilen,
        'quelle' => [$felder['suche'] => 'estab-erledigt'],
    ]) !== '' && !str_contains(
        estab_tabelle_markup([
            'id' => 'probe', 'spalten' => $mitKnopf, 'zeilen' => $zeilen,
            'quelle' => [$felder['suche'] => 'estab-erledigt'],
        ]),
        '<button type="button"'
    ),
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Die Suche greift auf das Markup einer Zelle zu statt auf ihren '
            . 'Wert. Eine Suche nach einem Klassennamen fände dann jede Zeile.'
    )
);

/* --- Der Leerzustand nennt den Grund und den Weg zurück --- */

$nichts = estab_tabelle_markup([
    'id' => 'probe', 'spalten' => $spalten, 'zeilen' => $zeilen,
    'quelle' => [$felder['suche'] => 'kommtnichtvor'],
    'leer' => 'Keine Meldung entspricht den gesetzten Filtern.',
]);
$assert(
    str_contains($nichts, 'Keine Meldung entspricht den gesetzten Filtern.')
        && str_contains($nichts, 'Filter zurücksetzen'),
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Der Leerzustand nennt den Grund oder den Weg zurück nicht. Eine '
            . 'leere Fläche ohne beides sieht aus wie ein Fehler.'
    )
);

/* --- Und der Waechter beisst --- */

$verdreht = $spalten;
$verdreht[2]['art'] = 'text';
$probe = estab_tabelle_markup([
    'id' => 'probe', 'spalten' => $verdreht, 'zeilen' => $zeilen,
    'quelle' => [$felder['sortierung'] => 'vorrang', $felder['richtung'] => 'ab'],
]);
preg_match_all(
    '~<td[^>]*><span class="estab-tabelle-klammer">([^<]*)</span>~',
    $probe,
    $zellen
);
$alsText = [];
foreach ($zellen[1] as $stelle => $wert) {
    if ($stelle % 4 === 0) {
        $alsText[] = $wert;
    }
}
$assert(
    $alsText !== $vorrang,
    'Die Vorrangspalte als Text sortiert liefert dieselbe Reihenfolge wie '
        . 'nach Dringlichkeit. Die Prüfung oben wäre damit kein Nachweis.'
);


/*
 * Die Zusicherungsliste gilt fuer jede eingesetzte Tabelle.
 *
 * > R5.5: „Alle Tabellen tragen denselben Aufbau und dieselbe Bedienung --
 * > ein Test haelt alle eingesetzten Tabellen gegen dieselbe
 * > Zusicherungsliste."
 *
 * Bis hierher pruefte diese Datei das Bauteil an einer erfundenen Tabelle.
 * Das laesst eine Luecke: Eine Seite kann das Bauteil aufrufen und ihm
 * lauter Spalten geben, die weder sortierbar noch durchsuchbar sind. Sie
 * saehe dann aus wie jede andere Liste und koennte nichts.
 *
 * Geprueft wird deshalb nicht der Quelltext der Seiten -- den kann man
 * lesen, ohne dass er etwas bedeutet --, sondern das Bauteil weist eine
 * solche Tabelle beim Bauen ab. Damit ist jede eingesetzte Tabelle
 * geprueft, auch die, die es noch nicht gibt.
 *
 * Ausgenommen sind Tafeln ohne Baender (`baender => false`): Eine
 * Statustafel mit vier festen Zeilen braucht keine Sortierung, und ein
 * Suchfeld darueber waere Beiwerk.
 */
$abgewiesen = static function (array $tabelle) use (&$assertions): ?string {
    try {
        estab_tabelle_markup($tabelle);
    } catch (Throwable $fehler) {
        return $fehler->getMessage();
    }
    return null;
};

$ohneSortierung = [
    'id' => 'probe-ohne-sortierung',
    'beschriftung' => 'Eine Liste ohne jede Sortierung',
    'spalten' => [
        ['schluessel' => 'a', 'kopf' => 'A', 'breite' => 50,
            'sortierbar' => false, 'suchbar' => true],
        ['schluessel' => 'b', 'kopf' => 'B', 'breite' => 50,
            'sortierbar' => false, 'suchbar' => true],
    ],
    'zeilen' => [['a' => '1', 'b' => '2']],
];
$meldung = $abgewiesen($ohneSortierung);
$assert(
    is_string($meldung) && str_contains($meldung, 'sortierbar'),
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Eine Tabelle ohne eine einzige sortierbare Spalte wird gebaut. Sie '
            . 'saehe aus wie jede andere Liste und koennte nichts.'
    )
);

$ohneSuche = [
    'id' => 'probe-ohne-suche',
    'beschriftung' => 'Eine Liste ohne jede Suche',
    'spalten' => [
        ['schluessel' => 'a', 'kopf' => 'A', 'breite' => 50,
            'sortierbar' => true, 'suchbar' => false],
        ['schluessel' => 'b', 'kopf' => 'B', 'breite' => 50,
            'sortierbar' => true, 'suchbar' => false],
    ],
    'zeilen' => [['a' => '1', 'b' => '2']],
];
$meldung = $abgewiesen($ohneSuche);
$assert(
    is_string($meldung) && str_contains($meldung, 'durchsuchbar'),
    estab_ux_requirement(
        'GES-TABELLE-SUCHE',
        'Eine Tabelle ohne eine einzige durchsuchbare Spalte wird gebaut. '
            . 'Das Suchfeld darueber faende dann nie etwas.'
    )
);

/*
 * Eine Tafel ohne Baender darf beides nicht haben -- sie zeigt einen
 * festen Stand, keine Liste.
 */
$assert(
    $abgewiesen([
        'id' => 'probe-tafel',
        'beschriftung' => 'Ein fester Stand',
        'baender' => false,
        'spalten' => [
            ['schluessel' => 'a', 'kopf' => 'A', 'breite' => 100,
                'sortierbar' => false, 'suchbar' => false],
        ],
        'zeilen' => [['a' => '1']],
    ]) === null,
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Eine Statustafel ohne Baender wird abgewiesen, obwohl sie weder '
            . 'Sortierung noch Suche braucht.'
    )
);

/*
 * Eine Tabelle, die ihre Auswahl selbst trifft, wird nicht abgewiesen.
 *
 * Das ist die Meldungsuebersicht: Sie sortiert und siebt in der Datenbank
 * ueber alle Seiten und reicht dem Bauteil nur die fertige Seite herein.
 * Ihre Spalten sind absichtlich nicht sortierbar -- eine Sortierung ueber
 * fuenfzig angezeigte Zeilen waere eine andere als die ueber
 * zwoelfhundert. Sie bringt ihre Bedienung unter `zusatzbaender` mit.
 *
 * Ohne diese Ausnahme haette die Regel oben die wichtigste Liste der
 * Anwendung abgewiesen. Diese Probe haelt fest, dass sie es nicht tut.
 */
$assert(
    $abgewiesen([
        'id' => 'probe-fremde-auswahl',
        'beschriftung' => 'Eine Liste, die selbst siebt',
        'spalten' => [
            ['schluessel' => 'a', 'kopf' => 'A', 'breite' => 100,
                'sortierbar' => false, 'suchbar' => false],
        ],
        'zeilen' => [['a' => '1']],
        'fremd' => [
            'treffer' => 1200,
            'gesamt' => 1200,
            'seite' => 1,
            'seiten' => 48,
        ],
    ]) === null,
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Eine Tabelle mit eigener Auswahl wird abgewiesen, obwohl sie ihre '
            . 'Sortierung und Suche selbst mitbringt.'
    )
);

/*
 * Und eine Tabelle, die beides hat, geht durch. Ohne diese Probe koennte
 * die Abweisung alles ablehnen und die beiden Pruefungen oben waeren
 * erfuellt, ohne etwas zu bedeuten.
 */
$assert(
    $abgewiesen([
        'id' => 'probe-vollstaendig',
        'beschriftung' => 'Eine ordentliche Liste',
        'spalten' => [
            ['schluessel' => 'a', 'kopf' => 'A', 'breite' => 50,
                'sortierbar' => true, 'suchbar' => true],
            ['schluessel' => 'b', 'kopf' => 'B', 'breite' => 50,
                'sortierbar' => false, 'suchbar' => false],
        ],
        'zeilen' => [['a' => '1', 'b' => '2']],
    ]) === null,
    'Eine Tabelle mit sortierbarer und durchsuchbarer Spalte wird abgewiesen.'
);

/* --- Die Zelle ist die Grenze --- */

/*
 * Ein inline-flex-Kasten misst sich an seinem Inhalt und nicht an der
 * Zelle, in der er steht. In "Stab lesen" waren das 82 Zellen: Die
 * Transportmarke "abgeschlossen" (120 Bildpunkte) lag in einer Spalte
 * von 97 und schob sich ueber die Vorrangstufe -- auf dem Bildschirm
 * stand "abgeschlossStaatsnot". Die Anschriftenspalte tat dasselbe.
 *
 * Zwei Regeln halten das: die Bindung an die Zelle, und die Abwesenheit
 * einer Gegenregel. `word-break: keep-all` stand einmal auf den Knoepfen
 * in Zellen und war damals richtig, weil die Zelle `overflow-wrap:
 * anywhere` sagte; mit `break-word` verhindert es genau den Bruch, den
 * ein zu langes Wort braucht. Wer es zurueckholt, holt den Ueberlauf
 * zurueck.
 */
$stil = (string) file_get_contents($root . "/estab-ui.css");
$assert(
    preg_match(
        '~\.estab-tabelle-blatt\s+tbody\s+td\s+\*\s*\{[^}]*max-width:\s*100%~su',
        $stil
    ) === 1,
    estab_ux_requirement(
        'GES-TABELLE-EINHEITLICH',
        'Nichts bindet den Inhalt einer Zelle an ihre Breite. Ein '
            . 'inline-flex-Kasten waechst dann ueber die Nachbarspalte.'
    )
);
$assert(
    preg_match(
        '~\.estab-tabelle-blatt\s+tbody\s+td[^{]*\{[^}]*word-break:\s*keep-all~su',
        $stil
    ) === 0,
    estab_ux_requirement(
        'GES-TABELLE-EINHEITLICH',
        '`word-break: keep-all` steht wieder in einer Zelle. Ein Wort, '
            . 'das allein nicht in die Spalte passt, kann dann nicht '
            . 'brechen und laeuft in die Nachbarspalte.'
    )
);

printf(
    "Gestaltung Tabellenbauteil: OK (%d assertions, %d Spalten, %d Zeilen)\n",
    $assertions,
    count($spalten),
    count($zeilen)
);
