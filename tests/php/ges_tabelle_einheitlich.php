<?php

declare(strict_types=1);

/**
 * Jede Liste kommt aus dem Tabellenbauteil -- und was noch nicht, steht hier.
 *
 * > „Ich möchte hier ein Tabellenframework das gut funktioniert und das dann
 * > immer wieder genutzt wird. So sind alle Tabellen gleich aufgebaut und die
 * > Funktionalität gleich."
 *
 * Eine Vorlage wird kopiert und läuft auseinander; genau das ist passiert,
 * und das Ergebnis waren sechs Rückmeldungen mit einer Ursache. Ein Bauteil
 * hat eine Aufrufstelle -- und deshalb kann eine Prüfung zählen.
 *
 * ## Was gezählt wird
 *
 * Jedes `<table` in den Verzeichnissen der Anwendung. Jedes.
 *
 * Vorher waren es nur zwei Klassennamen -- `estab-tool-table` und
 * `estab-list-table` --, und genau daran ist die Kontenliste der
 * Anmeldeseite vorbeigekommen: Sie hiess `estab-account-list`, stand in
 * keiner Zählung, und der Betreiber hat sie gefunden, nicht diese Prüfung.
 * Ein Wächter, der eine Liste bekannter Namen abfragt, findet immer nur,
 * was schon jemand aufgeschrieben hat.
 *
 * Deshalb jetzt umgekehrt: Erlaubt ist, was das Bauteil erzeugt. Alles
 * andere muss hier namentlich stehen -- entweder als dauerhafte Ausnahme
 * (das Papierraster des Vordrucks, docs/GESTALTUNG.md Abschnitt 12) oder
 * als noch offene Stelle mit ihrem Grund. Eine neue Tabelle mit einem neuen
 * Klassennamen fällt damit auf, statt durchzurutschen.
 *
 * ## Warum die Liste nicht leer ist
 *
 * Die sieben Flächen, die docs/TABELLEN.md Abschnitt 5 benennt, sind
 * umgestellt: Nachweisung Eingang, Ausgang und gemeinsam, „Stab lesen",
 * Anhänge, Benutzerliste, Meldungsübersicht, Vordruckliste.
 *
 * Beim Zählen sind weitere Datentabellen aufgetaucht, die dort nicht stehen
 * und in keiner Rückmeldung vorkamen: die Bücher ETB und TBB, die
 * Führungsstellenverwaltung, der Systemstand, die Empfängermatrix, die
 * Kategorienpflege und die übrigen Listenarten in liste.php. Sie stehen hier
 * namentlich. Die Zahl darf sinken, nicht steigen: Wer eine neue Tabelle von
 * Hand schreibt, muss diese Datei anfassen und den Grund hinterlassen.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once __DIR__ . '/lib/quelltext.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/**
 * Das Papierraster des Nachrichtenvordrucks -- dauerhafte Ausnahme.
 *
 * Der Vordruck bildet ein gedrucktes Formular nach. Sein Raster *ist* eine
 * Tabelle, und zwar seit 1978; ein Bauteil für Listen hat dort nichts zu
 * suchen. Diese Dateien zeichnen den Vordruck oder zeigen ihn an.
 *
 * @var array<string,string>
 */
$faksimile = [
    '4fach/4fachform.php' => 'Der Nachrichtenvordruck selbst.',
    '4fach/anhang.php' => 'Die Anlagenseite im Vordruckraster.',
    '4fach/logoff.php' => 'Die Abmeldeseite im alten Raster.',
];

/**
 * Noch nicht umgestellte Tabellen, je Datei mit ihrem Grund und ihrer Zahl.
 *
 * Die Zahl steht dabei, damit eine *zusätzliche* Tabelle in einer Datei,
 * die ohnehin schon offen ist, ebenfalls auffällt. Ohne sie wäre die
 * Ausnahme ein Freibrief für die ganze Datei.
 *
 * @var array<string,array{0:int,1:string}>
 */
$offeneDateien = [
    '4fach/liste.php' => [8, 'Die übrigen Listenarten -- Sichtung, '
        . 'Korrektur und die Administrationssichten -- sowie sechs '
        . 'Layouttabellen der alten Filterleiste. Die drei Nachweisungs'
        . 'zweige sind geloescht: Sie hatten keinen Aufrufer mehr.'],
    '4fueltg/ue_ltg.php' => [17, 'Der Vordruck der Führungsleitung und zwei '
        . 'Layouttabellen seiner Bedienleiste.'],
    '4fach/tools.php' => [6, 'Das alte Cockpit mit fest eingetragenen Grautönen.'],
    '4fadm/fuehrungsstelle.php' => [4, 'Die Schicht- und Dienstverwaltung.'],
    '4fadm/make_fkt.php' => [1, 'Die Empfängermatrix.'],
    '4fach/mainindex.php' => [1, 'Der Satzspiegel der Anmeldefelder -- '
        . 'Beschriftung und Feld nebeneinander, keine Liste.'],
    'handbuch/index.php' => [2, 'Zwei Übersichten im Handbuch. Sie stehen '
        . 'im Fließtext und werden nicht aus Daten erzeugt.'],
];

/*
 * Die Gesamtzahl der noch nicht umgestellten Tabellen. Sie darf sinken.
 *
 * Vorher stand hier 22 -- gezählt wurden aber nur zwei Klassennamen. Die
 * ehrliche Zahl ist höher, und das ist der Punkt: Eine Ratsche, die nur
 * zählt, was sie kennt, misst ihren eigenen Blick, nicht den Bestand.
 */
const ESTAB_TABELLEN_OFFEN = 25;

$verzeichnisse = [
    '4fach', '4fadm', '4fueltg', 'app', 'stabetb', 'fmtbb', 'stabinfo',
    'handbuch',
];
$gefunden = [];
$amBauteil = 0;
$imFaksimile = 0;
foreach ($verzeichnisse as $verzeichnis) {
    $pfad = $root . '/' . $verzeichnis;
    if (!is_dir($pfad)) {
        continue;
    }
    $dateien = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pfad, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($dateien as $datei) {
        if (!$datei->isFile() || $datei->getExtension() !== 'php') {
            continue;
        }
        $relativ = substr($datei->getPathname(), strlen($root) + 1);
        $quelle = file_get_contents($datei->getPathname());
        if (!is_string($quelle)) {
            continue;
        }
        $zeile = 0;
        foreach (preg_split('~\R~', $quelle) ?: [] as $text) {
            $zeile++;
            if (!str_contains($text, '<table')) {
                continue;
            }
            // Was das Bauteil erzeugt, ist in Ordnung -- und nur das.
            if (str_contains($text, 'estab-tabelle-blatt')) {
                $amBauteil++;
                continue;
            }
            if (array_key_exists($relativ, $faksimile)) {
                $imFaksimile++;
                continue;
            }
            $gefunden[$relativ][] = $zeile;
        }
    }
}

// Jede Datei mit eigener Tabelle steht namentlich in einer der beiden
// Listen. Eine neue Datei mit einer neuen Klasse fällt hier auf -- der
// Fehler, an dem die Kontenliste vorbeigekommen ist.
$unbekannt = [];
foreach ($gefunden as $relativ => $zeilen) {
    if (!array_key_exists($relativ, $offeneDateien)) {
        $unbekannt[] = $relativ . ':' . implode(',', $zeilen);
    }
}
$assert(
    $unbekannt === [],
    estab_ux_requirement(
        'GES-TABELLE-EINHEITLICH',
        'Diese Stellen schreiben ihr eigenes Tabellenmarkup, ohne in einer '
            . 'der beiden Listen zu stehen: ' . implode(' | ', $unbekannt)
            . '. Entweder die Tabelle kommt aus dem Bauteil, oder ihr Grund '
            . 'steht hier.'
    )
);

// Und keine Datei trägt mehr Tabellen, als für sie eingetragen sind.
$gewachsen = [];
foreach ($offeneDateien as $relativ => $eintrag) {
    $ist = count($gefunden[$relativ] ?? []);
    if ($ist > $eintrag[0]) {
        $gewachsen[] = $relativ . ': ' . $ist . ' statt ' . $eintrag[0];
    }
}
$assert(
    $gewachsen === [],
    estab_ux_requirement(
        'GES-TABELLE-EINHEITLICH',
        'Diese Dateien haben eine Tabelle dazubekommen: '
            . implode(', ', $gewachsen) . '. Eine offene Stelle ist kein '
            . 'Freibrief, dort weiterzubauen.'
    )
);

$summe = 0;
foreach ($gefunden as $zeilen) {
    $summe += count($zeilen);
}
$assert(
    $summe <= ESTAB_TABELLEN_OFFEN,
    estab_ux_requirement(
        'GES-TABELLE-EINHEITLICH',
        'Es gibt ' . $summe . ' selbst gebaute Tabellen statt höchstens '
            . ESTAB_TABELLEN_OFFEN . '. Die Zahl darf sinken, nicht steigen.'
    )
);


/*
 * Sichtbarer Text ist richtiges Deutsch, keine Umschrift.
 *
 * Die Kommentare dieses Bestandes schreiben "ueber" und "faellt" -- das
 * ist eine bewusste Gewohnheit und bleibt. Was der Bedienende *liest*,
 * darf so nicht aussehen: "VERKNUEPFT UEBER" in einer Spaltenüberschrift
 * ist kein Deutsch, und in einer Führungsstelle steht die Anwendung neben
 * einem gedruckten Vordruck, der es richtig schreibt.
 *
 * Genau dieser Fehler ist beim Umstellen der ETB-Verknüpfungsliste
 * passiert, und gefunden hat ihn ein Blick in den Browser, keine Prüfung.
 * Bei den noch offenen Tabellen -- jede mit deutschen Überschriften --
 * passiert er wieder.
 *
 * ## Warum eine Wortliste und keine Buchstabenprobe
 *
 * "ue", "ae", "ss" kommen in richtigem Deutsch ständig vor: aktuelles,
 * Ereignisse, Abfasszeit. Eine Probe auf Buchstabenpaare meldete beim
 * ersten Versuch vier Fehlalarme und keinen echten Fund. Aufgeführt sind
 * deshalb nur Formen, die in sichtbarem Text nie richtig sind.
 */
$umschriften = [
    'ueber', 'Ueber', 'fuer', 'Fuer', 'zurueck', 'Zurueck',
    'Kuerzel', 'kuerzel', 'Groesse', 'groesse', 'oeffnen', 'Oeffnen',
    'loeschen', 'Loeschen', 'waehlen', 'Waehlen', 'aendern', 'Aendern',
    'naechste', 'Naechste', 'moeglich', 'Moeglich', 'Anhaenge', 'anhaenge',
    'Empfaenger', 'empfaenger', 'schliessen', 'Schliessen', 'Massnahme',
    'massnahme', 'Strasse', 'Verknuepf', 'verknuepf', 'Uebermittlung',
    'uebermittlung', 'Vordruecke', 'vordruecke', 'gemaess', 'Gemaess',
    'Erlaeuterung', 'erlaeuterung', 'Auswaehlen', 'auswaehlen',
];

/**
 * Die sichtbaren Zeichenketten einer Tabellenbeschreibung.
 *
 * `kopf` ist die Spaltenüberschrift, `beschriftung` die Bildunterschrift,
 * `leer` der Satz bei leerer Trefferliste, `filtername` die erste Zeile
 * einer Auswahlliste. Alle vier liest der Bedienende.
 */
$sichtbareSchluessel = ['kopf', 'beschriftung', 'leer', 'filtername'];
$umschriftFunde = [];
foreach ($verzeichnisse as $verzeichnis) {
    $pfad = $root . '/' . $verzeichnis;
    if (!is_dir($pfad)) {
        continue;
    }
    $dateien = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pfad, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($dateien as $datei) {
        if (!$datei->isFile() || $datei->getExtension() !== 'php') {
            continue;
        }
        $quelle = file_get_contents($datei->getPathname());
        if (!is_string($quelle)) {
            continue;
        }
        $relativ = substr($datei->getPathname(), strlen($root) + 1);
        foreach ($sichtbareSchluessel as $schluessel) {
            $muster = '~["\']' . $schluessel . '["\']\s*=>\s*(["\'])(.*?)(?<!\\\\)\1~s';
            if (preg_match_all($muster, $quelle, $treffer, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($treffer as $einzeln) {
                foreach ($umschriften as $umschrift) {
                    if (str_contains($einzeln[2], $umschrift)) {
                        $umschriftFunde[] = $relativ . ': ' . $schluessel
                            . ' = "' . $einzeln[2] . '"';
                        break;
                    }
                }
            }
        }
    }
}
$assert(
    $umschriftFunde === [],
    estab_ux_requirement(
        'UX-SPRACHE-VORSCHRIFT',
        'Diese sichtbaren Tabellentexte stehen in Umschrift statt in '
            . 'richtigem Deutsch: ' . implode(' | ', array_slice($umschriftFunde, 0, 6))
    )
);

// Beisst die Pruefung? Ein eingebauter Fall muss auffallen -- und ein
// richtig geschriebener darf es nicht.
$probeTexte = [
    'Verknuepft ueber' => true,
    'Verknüpft über' => false,
    'Aktuelles PDF' => false,
    'Ereignisse' => false,
    'Abfasszeit' => false,
];
foreach ($probeTexte as $text => $sollAuffallen) {
    $faellt = false;
    foreach ($umschriften as $umschrift) {
        if (str_contains($text, $umschrift)) {
            $faellt = true;
            break;
        }
    }
    $assert(
        $faellt === $sollAuffallen,
        'Die Umschriftpruefung urteilt falsch ueber "' . $text . '": '
            . ($faellt ? 'gemeldet' : 'durchgelassen')
    );
}


/*
 * Kein Listenzweig ohne Aufrufer.
 *
 * liste.php trug drei Nachweisungszweige -- FmNwE, FmNwA, FmNw --, die
 * niemand mehr aufrief: Die Nachweisung geht seit der Umstellung durch
 * app/nachweisung.php. Sie standen als drei weitere handgeschriebene
 * Tabellen im Baum und in der Ausnahmeliste oben.
 *
 * Toter Code, der aussieht wie eine Liste, ist teurer als eine Liste:
 * Wer die Nachweisung sucht, findet zwei Umsetzungen und muss erst
 * herausfinden, welche gilt. Und wer eine davon verbessert, verbessert
 * womoeglich die falsche.
 *
 * Geprueft wird gegen die Aufrufstellen, nicht gegen eine Liste erlaubter
 * Namen: Ein Zweig ist genau dann lebendig, wenn ihn jemand baut.
 */
$listenQuelle = estab_test_ohne_kommentare(
    (string) file_get_contents($root . '/4fach/liste.php')
);
$gebaut = [];
foreach ([
    $root . '/4fach/mainindex.php',
    $root . '/4fueltg/ue_ltg.php',
    $root . '/4fach/liste.php',
] as $aufrufer) {
    $quelle = file_get_contents($aufrufer);
    if (!is_string($quelle)) {
        continue;
    }
    if (preg_match_all(
        '~new\s+listen\s*\(\s*["\']([A-Za-z0-9_]+)["\']~',
        $quelle,
        $treffer
    )) {
        foreach ($treffer[1] as $art) {
            $gebaut[$art] = true;
        }
    }
    // get_list ruft dieselbe Verzweigung mit einer Art auf.
    if (preg_match_all(
        '~get_list\s*\(\s*["\']([A-Za-z0-9_]+)["\']~',
        $quelle,
        $treffer
    )) {
        foreach ($treffer[1] as $art) {
            $gebaut[$art] = true;
        }
    }
}
$verwaisteZweige = [];
if (preg_match_all(
    '~^\s*case\s+["\']([A-Za-z0-9_]+)["\']\s*:~m',
    $listenQuelle,
    $zweige
)) {
    foreach (array_unique($zweige[1]) as $zweig) {
        // Die Blaetterschritte sind keine Listenarten.
        if (in_array($zweig, ['start', 'back', 'for', 'end', 'global'], true)) {
            continue;
        }
        if (!array_key_exists($zweig, $gebaut)) {
            $verwaisteZweige[] = $zweig;
        }
    }
}
sort($verwaisteZweige);
$assert(
    $verwaisteZweige === [],
    estab_ux_requirement(
        'GES-TABELLE-EINHEITLICH',
        'Diese Listenzweige baut niemand mehr: '
            . implode(', ', $verwaisteZweige) . '. Toter Code, der aussieht '
            . 'wie eine Liste, ist teurer als eine Liste -- wer die Sache '
            . 'sucht, findet zwei Umsetzungen und weiss nicht, welche gilt.'
    )
);

/*
 * Und die sieben Flächen aus docs/TABELLEN.md Abschnitt 5 rufen das Bauteil
 * wirklich auf. Ohne diese Hälfte zählte die Prüfung oben nur, was fehlt --
 * nicht, was da ist.
 */
$flaechen = [
    'app/nachweisung.php' => 'Nachweisung Eingang, Ausgang und gemeinsam',
    'app/anhang_tabelle.php' => 'Anhänge',
    '4fadm/users.php' => 'Benutzerliste',
    'app/message_list_ui.php' => 'Meldungsübersicht',
    '4fach/vordrucke.php' => 'Vordruckliste',
    '4fach/liste.php' => '„Stab lesen"',
];
foreach ($flaechen as $datei => $name) {
    $quelle = file_get_contents($root . '/' . $datei);
    $assert(
        is_string($quelle)
            && (str_contains($quelle, 'estab_tabelle_markup(')
                || str_contains($quelle, 'estab_tabelle_markup (')
                || str_contains($quelle, 'estab_tabelle_ausgeben(')),
        estab_ux_requirement(
            'GES-TABELLE-EINHEITLICH',
            'Die Fläche „' . $name . '" (' . $datei . ') ruft das '
                . 'Tabellenbauteil nicht mehr auf.'
        )
    );
}

/*
 * Beisst die Pruefung?
 *
 * Zwei Proben, und die zweite ist die wichtigere: eine Tabelle mit einem
 * *neuen* Klassennamen in einer Datei, die niemand auf dem Zettel hat.
 * Genau so ist die Kontenliste durchgerutscht -- sie hiess
 * `estab-account-list`, und die alte Pruefung fragte nach zwei anderen
 * Namen. Eine Pruefung, die nur bekannte Namen kennt, findet nie etwas
 * Neues.
 */
$probeZeilen = [
    'echo "<table class=\"estab-tabelle-blatt\">";',
    'echo "<table class=\"voellig-neuer-name\">";',
    'echo "<p>keine Tabelle</p>";',
];
$probeOffen = 0;
$probeBauteil = 0;
foreach ($probeZeilen as $text) {
    if (!str_contains($text, '<table')) {
        continue;
    }
    if (str_contains($text, 'estab-tabelle-blatt')) {
        $probeBauteil++;
        continue;
    }
    $probeOffen++;
}
$assert(
    $probeOffen === 1 && $probeBauteil === 1,
    'Die Pruefung erkennt eine Tabelle mit unbekanntem Klassennamen nicht '
        . 'wieder; ihre Ruhe waere kein Beweis.'
);

printf(
    "Gestaltung Tabellen einheitlich: OK (%d assertions, %d am Bauteil, "
        . "%d im Papierraster, %d noch offen in %d Dateien)\n",
    $assertions,
    $amBauteil,
    $imFaksimile,
    $summe,
    count($gefunden)
);
