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
 * Datentabellen, erkennbar an den beiden Klassen, mit denen diese Anwendung
 * sie seit jeher auszeichnet: `estab-tool-table` und `estab-list-table`. Die
 * Layouttabellen des Nachrichtenvordrucks zählen nicht mit -- sie stellen
 * keine Liste dar, sondern das Papierraster, und das ist eine ausdrückliche
 * Ausnahme (docs/GESTALTUNG.md Abschnitt 12).
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

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/**
 * Noch nicht umgestellte Datentabellen, je mit ihrem Grund.
 *
 * @var array<string,string>
 */
$ausnahmen = [
    '4fach/liste.php' => 'Die übrigen Listenarten der Meldungsliste: '
        . 'Sichtung, Korrektur, die Administrationssichten und die '
        . 'Nachweisungszweige, die nachwea.php nicht mehr aufruft.',
    '4fach/fuehrungsstelle.php' => 'Die Führungsstellenübersicht.',
    '4fach/katgoedt.php' => 'Die Pflege der Kategorien.',
    '4fadm/fuehrungsstelle.php' => 'Die Führungsstellenverwaltung.',
    '4fadm/make_fkt.php' => 'Die Empfängermatrix.',
    '4fadm/system_status.php' => 'Der Systemstand.',
    'stabetb/etb.php' => 'Das Einsatztagebuch.',
    'fmtbb/tbb.php' => 'Das Technische Betriebsbuch.',
];

/*
 * Die Zahl der noch selbst gebauten Datentabellen. Sie darf sinken.
 */
const ESTAB_TABELLEN_OFFEN = 22;

$verzeichnisse = [
    '4fach', '4fadm', '4fueltg', 'app', 'stabetb', 'fmtbb', 'stabinfo',
    'handbuch',
];
$gefunden = [];
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
            if (!str_contains($text, 'estab-tool-table')
                && !str_contains($text, 'estab-list-table')) {
                continue;
            }
            $gefunden[] = $relativ . ':' . $zeile;
        }
    }
}

$offen = [];
foreach ($gefunden as $stelle) {
    $datei = substr($stelle, 0, (int) strpos($stelle, ':'));
    if (!array_key_exists($datei, $ausnahmen)) {
        $offen[] = $stelle;
    }
}
$assert(
    $offen === [],
    estab_ux_requirement(
        'GES-TABELLE-EINHEITLICH',
        'Diese Seiten schreiben ihr eigenes Tabellenmarkup, ohne in der '
            . 'Ausnahmeliste zu stehen: ' . implode(', ', $offen)
            . '. Eine Vorlage wird kopiert und läuft auseinander -- entweder '
            . 'die Tabelle kommt aus dem Bauteil, oder ihr Grund steht hier.'
    )
);
$assert(
    count($gefunden) <= ESTAB_TABELLEN_OFFEN,
    estab_ux_requirement(
        'GES-TABELLE-EINHEITLICH',
        'Es gibt ' . count($gefunden) . ' selbst gebaute Datentabellen statt '
            . 'höchstens ' . ESTAB_TABELLEN_OFFEN . '. Die Liste darf sinken, '
            . 'nicht steigen.'
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

// Beisst die Pruefung? Eine erfundene Stelle ausserhalb der Ausnahmeliste
// muss auffallen.
$probe = ['4fach/erfunden.php:1', '4fach/liste.php:9'];
$probeOffen = [];
foreach ($probe as $stelle) {
    $datei = substr($stelle, 0, (int) strpos($stelle, ':'));
    if (!array_key_exists($datei, $ausnahmen)) {
        $probeOffen[] = $stelle;
    }
}
$assert(
    $probeOffen === ['4fach/erfunden.php:1'],
    'Die Pruefung erkennt eine Tabelle ausserhalb der Ausnahmeliste nicht '
        . 'wieder; ihre Ruhe waere kein Beweis.'
);

printf(
    "Gestaltung Tabellen einheitlich: OK (%d assertions, %d Flächen am "
        . "Bauteil, %d Tabellen noch offen in %d Dateien)\n",
    $assertions,
    count($flaechen),
    count($gefunden),
    count($ausnahmen)
);
