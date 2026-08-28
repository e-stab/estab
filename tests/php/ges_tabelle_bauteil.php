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

printf(
    "Gestaltung Tabellenbauteil: OK (%d assertions, %d Spalten, %d Zeilen)\n",
    $assertions,
    count($spalten),
    count($zeilen)
);
