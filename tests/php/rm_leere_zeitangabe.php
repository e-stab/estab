<?php

declare(strict_types=1);

/**
 * Eine Zeitangabe, die niemand gemacht hat, ist keine leere Zeichenkette.
 *
 * Der Fernmelder konnte keinen Eingang mehr aufnehmen: Absenden führte zu
 * einem 500er ohne jede Erklärung. Im Protokoll stand nur „Could not execute
 * message operation".
 *
 * Die Kette war diese:
 *
 * 1. Die Abfassungszeit ist dem Fernmelder seit R12 gesperrt. Bei einem
 *    Eingang bleibt sie leer -- so gewollt, denn er hat die Nachricht nicht
 *    abgefasst.
 * 2. Das gesperrte Feld sendet trotzdem mit, als verborgenes Feld mit
 *    leerem Wert.
 * 3. `konv_taktime_datetime("")` gab `""` zurück -- keine Zeitangabe, aber
 *    auch kein Eingeständnis, dass es keine gibt.
 * 4. `''` in eine DATETIME-Spalte ist im strikten Modus Fehler 1292,
 *    SQLSTATE 22007. Der ganze Vorgang scheiterte.
 *
 * Beide Enden werden hier festgehalten.
 *
 * **Die Umwandlung sagt die Wahrheit.** Was keine taktische Zeitgruppe ist,
 * ergibt keine Zeit -- also `null`. Die Spalten lassen NULL zu; das ist die
 * Schreibweise für „nicht angegeben". `""` ist keine.
 *
 * **Die Datenbank darf sagen, was ihr fehlt.** Die Ausnahme trug bisher
 * keinen Hinweis. Sie trägt jetzt Fehlernummer und SQLSTATE -- Codes, keine
 * Werte: MySQL schreibt in seinen Klartext gern den beanstandeten Wert, und
 * der gehört weder in eine Ausnahme noch in ein Protokoll.
 */

$root = dirname(__DIR__, 2);

$previousDirectory = getcwd();
if (!is_string($previousDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
} finally {
    chdir($previousDirectory);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Die Umwandlung braucht die Monatstabelle aus dem Laufzeitverzeichnis. */
$umwandeln = static function (string $wert) use ($root): mixed {
    $zuvor = getcwd();
    if (!is_string($zuvor) || !chdir($root . '/4fach')) {
        throw new RuntimeException('Cannot enter the message runtime directory');
    }
    try {
        return konv_taktime_datetime($wert);
    } finally {
        chdir($zuvor);
    }
};

/* --- Keine Angabe ist null, nicht "" --- */

foreach ([
    '' => 'die leere Eingabe eines gesperrten Feldes',
    '1810' => 'die alte vierstellige Uhrzeit',
    'abc' => 'unbrauchbarer Text',
    '2026-08-29 18:10:00' => 'ein Zeitstempel in Datenbankschreibweise',
] as $eingabe => $was) {
    $ergebnis = $umwandeln((string) $eingabe);
    $assert(
        $ergebnis === null,
        'Für ' . $was . ' (' . var_export($eingabe, true) . ') liefert die '
            . 'Umwandlung ' . var_export($ergebnis, true) . ' statt null. '
            . 'Eine leere Zeichenkette in einer DATETIME-Spalte ist im '
            . 'strikten Modus Fehler 1292 -- der ganze Vorgang scheitert.'
    );
}

// Eine echte taktische Zeitgruppe ergibt weiterhin ihren Zeitpunkt.
$assert(
    $umwandeln('292300aug2026') === '2026-08-29 23:00:00',
    'Eine gültige taktische Zeitgruppe wird nicht mehr umgewandelt: '
        . var_export($umwandeln('292300aug2026'), true)
);

/* --- Und die Datenbank darf sagen, was ihr fehlt --- */

$quelle = file_get_contents($root . '/app/message_repository.php');
$assert(is_string($quelle), 'Das Nachrichtenlager ist nicht lesbar.');
$quelle = (string) $quelle;

$assert(
    str_contains($quelle, 'function estab_message_error_key('),
    'Die Ausnahme einer fehlgeschlagenen Datenbankoperation trägt keinen '
        . 'Fehlerschlüssel. Im Protokoll stand nur „Could not execute '
        . 'message operation" -- wer einen Eingang nicht aufnehmen konnte, '
        . 'erfuhr nirgends, woran es lag.'
);
if (preg_match(
    '~function estab_message_error_key\([^)]*\): string\s*\{(.*?)\n\}~s',
    $quelle,
    $rumpf
) !== 1) {
    throw new RuntimeException('estab_message_error_key ist nicht lesbar.');
}
$assert(
    str_contains($rumpf[1], '->errno') && str_contains($rumpf[1], '->sqlstate'),
    'Der Fehlerschlüssel nennt weder Fehlernummer noch SQLSTATE.'
);
$assert(
    !str_contains($rumpf[1], '->error'),
    'Der Fehlerschlüssel nimmt den Klartext der Datenbank auf. MySQL '
        . 'schreibt dort gern den beanstandeten Wert hinein, und der gehört '
        . 'weder in eine Ausnahme noch in ein Protokoll.'
);
$assert(
    substr_count($quelle, 'estab_message_error_key($connection)') >= 3,
    'Nicht jeder Fehlerweg der Datenbankoperation trägt den Schlüssel mit.'
);

printf("Leere Zeitangabe: OK (%d assertions)\n", $assertions);
