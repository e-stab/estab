<?php

declare(strict_types=1);

/*
 * Eine Funktion, die nur in einem Zweig entsteht, darf nicht im anderen
 * gerufen werden.
 *
 * Der Befund: 4fadm/fuehrungsstelle.php führt zwei Seiten in einer Datei,
 * ausgewählt nach dem Berechtigungsmodus des aktiven Einsatzes --
 * "locker" zeigt die Zugangsschichten, "streng" die Dienstschichten.
 * Beide Seiten stehen als Rumpf eines `if`. Wer dort eine gemeinsame
 * Hilfe ablegt, legt sie nur für einen der beiden Fälle an: In PHP
 * entsteht eine Funktion in einem bedingten Block erst, wenn der Block
 * durchlaufen wird.
 *
 * Genau das ist passiert. Die Tafelhilfe stand im lockeren Zweig und
 * wurde im strengen gerufen; die Seite brach dort mit "Call to undefined
 * function" ab -- ohne sichtbare Meldung, denn Fehleranzeige ist im
 * Betrieb aus. Der Bildschirm endete mitten im Dokument.
 *
 * Der eigentliche Fund war aber nicht der Fehler, sondern dass 171
 * Prüfungen grün blieben, während die Seite tot war. `php -l` sieht das
 * nicht: Der Quelltext ist einwandfrei, nur die Reihenfolge zur Laufzeit
 * nicht. Diese Prüfung sieht es.
 */

require_once __DIR__ . '/lib/quelltext.php';

$assertions = 0;
$assert = static function (bool $bedingung, string $meldung) use (&$assertions): void {
    $assertions++;
    if (!$bedingung) {
        throw new RuntimeException($meldung);
    }
};

$wurzel = dirname(__DIR__, 2);

/**
 * Die Funktionen, die in einem bedingten Block der obersten Ebene stehen,
 * je Block mit seinen Grenzen.
 *
 * @return list<array{name:string,von:int,bis:int}>
 */
$bedingteFunktionen = static function (string $quelle): array {
    $marken = token_get_all($quelle);
    $tiefe = 0;
    $bloecke = [];
    $offen = [];
    $anzahl = count($marken);
    for ($i = 0; $i < $anzahl; $i++) {
        $marke = $marken[$i];
        if ($marke === '{') {
            $tiefe++;
            continue;
        }
        if ($marke === '}') {
            $tiefe--;
            foreach ($offen as $stelle => $block) {
                if ($tiefe < $block['tiefe']) {
                    $bloecke[] = ['von' => $block['von'], 'bis' => $i];
                    unset($offen[$stelle]);
                }
            }
            continue;
        }
        if (is_array($marke) && $marke[0] === T_IF && $tiefe === 0) {
            /*
             * Ein Ersatzstueck ist ausgenommen.
             *
             * `if (!function_exists('mysql_error')) { function mysql_error()
             * ... }` ist genau das Muster "lege es an, falls es fehlt": Wird
             * der Block uebersprungen, gibt es die Funktion bereits. Ein
             * Aufruf danach ist dann richtig, nicht falsch. Ohne diese
             * Ausnahme meldete die Pruefung app/legacy_mysql.php -- die
             * Bruecke zur entfernten ext/mysql-Schnittstelle.
             */
            $ersatzstueck = false;
            for ($k = $i + 1; $k < min($i + 12, $anzahl); $k++) {
                if (is_array($marken[$k]) && $marken[$k][0] === T_STRING
                    && $marken[$k][1] === 'function_exists') {
                    $ersatzstueck = true;
                    break;
                }
            }
            if (!$ersatzstueck) {
                $offen[] = ['tiefe' => 1, 'von' => $i];
            }
        }
    }
    $gefunden = [];
    foreach ($bloecke as $block) {
        for ($i = $block['von']; $i <= $block['bis']; $i++) {
            if (!is_array($marken[$i]) || $marken[$i][0] !== T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $block['bis']; $j++) {
                if (is_array($marken[$j]) && $marken[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($marken[$j]) && $marken[$j][0] === T_STRING) {
                    $gefunden[] = [
                        'name' => $marken[$j][1],
                        'von' => $block['von'],
                        'bis' => $block['bis'],
                    ];
                }
                break;
            }
        }
    }
    return $gefunden;
};

$dateien = [];
$lauf = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS)
);
foreach ($lauf as $eintrag) {
    $pfad = $eintrag->getPathname();
    if (!str_ends_with($pfad, '.php')) {
        continue;
    }
    $relativ = substr($pfad, strlen($wurzel) + 1);
    if (str_starts_with($relativ, 'tests/') || str_starts_with($relativ, 'tools/')
        || str_contains($relativ, '/vendor/')) {
        continue;
    }
    $dateien[$relativ] = (string) file_get_contents($pfad);
}

$befunde = [];
$geprueft = 0;
foreach ($dateien as $relativ => $quelle) {
    foreach ($bedingteFunktionen($quelle) as $funktion) {
        $geprueft++;
        $marken = token_get_all($quelle);
        $anzahl = count($marken);
        for ($i = 0; $i < $anzahl; $i++) {
            if ($i >= $funktion['von'] && $i <= $funktion['bis']) {
                continue;
            }
            if (!is_array($marken[$i]) || $marken[$i][0] !== T_STRING) {
                continue;
            }
            if ($marken[$i][1] !== $funktion['name']) {
                continue;
            }
            // Nur ein Aufruf zaehlt, keine Erwaehnung in einer Zeichenkette.
            $naechste = $marken[$i + 1] ?? null;
            if ($naechste !== '(') {
                continue;
            }
            $befunde[] = $relativ . ': ' . $funktion['name'] . '() steht in '
                . 'einem bedingten Block, wird aber in Zeile '
                . $marken[$i][2] . ' ausserhalb gerufen';
            break;
        }
    }
}

$assert(
    $befunde === [],
    'Diese Funktionen entstehen nur in einem Zweig und werden in einem '
        . 'anderen gerufen -- zur Laufzeit ein "Call to undefined function", '
        . 'im Quelltext unsichtbar: ' . implode(' | ', $befunde)
);

printf(
    "Bedingte Funktionen: OK (%d assertions, %d bedingte Definitionen in %d Dateien)\n",
    $assertions,
    $geprueft,
    count($dateien)
);
