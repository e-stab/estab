<?php

/*
 * Eine Zeitspalte sortiert nach Zeitpunkt -- oder gar nicht.
 *
 * Der Befund, der diese Pruefung ausgeloest hat: Vier Listen gaben ihrer
 * Zeitspalte die kurze taktische Form "TThhmm" mit, also etwa "240215".
 * Das Bauteil reichte sie an strtotime() weiter, und strtotime() liest
 * "030215" bereitwillig als Uhrzeit 03:02:15 *von heute*. Die Folge war
 * keine Fehlermeldung, sondern eine falsche Reihenfolge: Der 25. August
 * stand vor dem 3. August und der 3. Juli dazwischen.
 *
 * Eine Ziffernfolge ohne Monat ist kein Zeitpunkt. Sie kann es nicht
 * sein: "030215" ist der 3. um 02:15 -- welchen Monats, welchen Jahres?
 * Die Frage hat keine Antwort, und ein Zerleger, der sich trotzdem eine
 * gibt, ist schlimmer als einer, der nichts liefert.
 */

require_once __DIR__ . '/../../app/ux_rules.php';
require_once __DIR__ . '/../../app/datetime.php';
require_once __DIR__ . '/../../app/nv_datetime_group.php';
require_once __DIR__ . '/../../app/tabelle.php';
require_once __DIR__ . '/lib/quelltext.php';

$assertions = 0;
$assert = static function (bool $bedingung, string $meldung) use (&$assertions): void {
    $assertions++;
    if (!$bedingung) {
        throw new RuntimeException($meldung);
    }
};

/* --- 1. Der Zerleger raet nicht --- */

$mehrdeutig = ['240215', '030215', '251530', '0215', '24021500', '12'];
foreach ($mehrdeutig as $form) {
    $assert(
        estab_tabelle_zeitpunkt($form) === null,
        estab_ux_requirement(
            'GES-TABELLE-SORTIERUNG',
            'Der Zerleger deutet "' . $form . '" als Zeitpunkt ('
                . var_export(estab_tabelle_zeitpunkt($form), true) . '). '
                . 'Eine blosse Ziffernfolge nennt keinen Monat und kein '
                . 'Jahr und ist damit kein Zeitpunkt.'
        )
    );
}

/* --- 2. Was er weiterhin versteht --- */

$monate = estab_nv_month_abbreviations();
$lang = estab_datetime_to_tactical('2026-08-24 02:15:26', $monate);
$assert(
    $lang !== '',
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Die lange taktische Form laesst sich nicht bilden.'
    )
);
foreach ([$lang, '2026-08-24 02:15:26', '2026-08-24'] as $form) {
    $assert(
        estab_tabelle_zeitpunkt($form) !== null,
        estab_ux_requirement(
            'GES-TABELLE-SORTIERUNG',
            'Der Zerleger versteht "' . $form . '" nicht mehr. Die lange '
                . 'taktische Form und die Datenbankform muessen tragen.'
        )
    );
}

/*
 * Und die Reihenfolge stimmt: fuenf Zeitpunkte ueber drei Monate, in der
 * langen Form sortiert, ergeben dieselbe Folge wie ihre Datenbankwerte.
 */
$echte = [
    '2026-07-03 09:05:00', '2026-08-03 02:15:00', '2026-08-24 02:15:26',
    '2026-08-24 23:59:00', '2026-08-25 15:30:00',
];
$gemischt = [$echte[4], $echte[1], $echte[0], $echte[3], $echte[2]];
$lange = array_map(
    static fn (string $e): string => estab_datetime_to_tactical($e, $monate),
    $gemischt
);
usort(
    $lange,
    static fn (string $a, string $b): int =>
        estab_tabelle_zeitpunkt($a) <=> estab_tabelle_zeitpunkt($b)
);
$erwartet = array_map(
    static fn (string $e): string => estab_datetime_to_tactical($e, $monate),
    $echte
);
$assert(
    $lange === $erwartet,
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Fuenf Zeitpunkte ueber drei Monate kommen in der Reihenfolge '
            . implode(', ', $lange) . ' statt ' . implode(', ', $erwartet) . '.'
    )
);

/* --- 3. Keine Liste gibt ihrer Zeitspalte die kurze Form --- */

/*
 * Statisch geprueft, weil der Fehler statisch ist: Wer "stak" in ein Feld
 * schreibt, das eine Spalte der Art "zeit" liest, hat ihn. Die Zuordnung
 * Feld -> Spaltenart steht in derselben Datei, also ist sie nachlesbar.
 */
$wurzel = dirname(__DIR__, 2);
$dateien = [];
$verzeichnisse = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS)
);
foreach ($verzeichnisse as $eintrag) {
    $pfad = $eintrag->getPathname();
    if (!str_ends_with($pfad, '.php')) {
        continue;
    }
    $relativ = substr($pfad, strlen($wurzel) + 1);
    if (str_starts_with($relativ, 'tests/') || str_starts_with($relativ, 'tools/')
        || str_contains($relativ, '/vendor/')) {
        continue;
    }
    $dateien[$relativ] = estab_test_ohne_kommentare(
        (string) file_get_contents($pfad)
    );
}

$verdacht = [];
foreach ($dateien as $relativ => $quelle) {
    // Welche Schluessel gehoeren zu einer Spalte der Art "zeit"?
    if (preg_match_all(
        '~[\'"]schluessel[\'"]\s*=>\s*[\'"]([a-z_0-9]+)[\'"](.{0,220}?)[\'"]art[\'"]\s*=>\s*[\'"]zeit[\'"]~su',
        $quelle,
        $treffer,
        PREG_SET_ORDER
    ) !== 0) {
        foreach ($treffer as $t) {
            $schluessel = $t[1];
            // Bekommt derselbe Schluessel irgendwo die kurze Form?
            foreach ($dateien as $andere => $inhalt) {
                if (preg_match(
                    '~[\'"]' . preg_quote($schluessel, '~')
                        . '[\'"]\s*=>\s*([^,;\n]{0,160})~u',
                    $inhalt,
                    $rechts
                ) !== 1) {
                    continue;
                }
                $ausdruck = $rechts[1];
                if (str_contains($ausdruck, "['stak']")
                    || str_contains($ausdruck, '["stak"]')) {
                    $verdacht[] = $andere . ': ' . $schluessel;
                    continue;
                }
                /*
                 * Eine Stufe weiter: Steht rechts nur eine Variable, dann
                 * entscheidet, woher *die* ihren Wert hat. Die Nachweisung
                 * ist genau dieser Fall -- sie holt "stak" drei Zeilen
                 * frueher. Ohne diesen Schritt schaute die Pruefung an
                 * ihrem eigenen Befund vorbei.
                 */
                if (preg_match('~\A\$([a-zA-Z_][a-zA-Z_0-9]*)~', $ausdruck, $name) !== 1) {
                    continue;
                }
                if (preg_match(
                    '~\$' . preg_quote($name[1], '~')
                        . '\s*=[^;]{0,200}\[[\'"]stak[\'"]\]~u',
                    $inhalt
                ) === 1) {
                    $verdacht[] = $andere . ': ' . $schluessel;
                }
            }
        }
    }
}
$verdacht = array_values(array_unique($verdacht));
$assert(
    $verdacht === [],
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Diese Spalten der Art "zeit" bekommen die kurze taktische Form, '
            . 'die keinen Monat nennt: ' . implode(' | ', $verdacht)
            . '. Der Wert gehoert in der langen Form hinterlegt; die kurze '
            . 'Form ist eine Anzeige und gehoert in "zelle".'
    )
);

/* --- 4. Wer den Wert setzt, setzt auch die Anzeige --- */

/*
 * Die Trennung hat eine zweite Haelfte: Der Wert traegt den Monat, die
 * Anzeige bleibt kurz. Wer nur die erste umsetzt, bekommt eine richtig
 * sortierte, aber leere Spalte -- ein Fehler, der beim Lesen des
 * Quelltextes nicht auffaellt, weil dort ja alles gesetzt scheint.
 */
$ungleich = [];
foreach ($dateien as $relativ => $quelle) {
    $wert = preg_match_all('~[\'"]zeit[\'"]\s*=>\s*estab_liste_zeitwert~u', $quelle);
    $anzeige = preg_match_all('~[\'"]zeit_kurz[\'"]\s*=>~u', $quelle);
    if ($wert !== $anzeige) {
        $ungleich[] = $relativ . ': ' . $wert . ' Werte, ' . $anzeige . ' Anzeigen';
    }
}
$assert(
    $ungleich === [],
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Hier steht der Zeitwert ohne seine Anzeige oder umgekehrt: '
            . implode(' | ', $ungleich) . '. Die Spalte bliebe leer.'
    )
);

printf("Zeitsortierung: OK (%d assertions)\n", $assertions);
