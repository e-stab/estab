<?php

declare(strict_types=1);

/**
 * Welche Station welches Feld ausfuellen darf -- vollstaendig.
 *
 * Der Nachrichtenvordruck wird von mehreren Haenden bearbeitet, und jede
 * bekommt genau die Felder, die ihr gehoeren. Drei Zuweisungen waren falsch:
 *
 *   * Der Absender war dem Fernmelder gesperrt. Er kennt ihn aber -- bei
 *     einer eingehenden Nachricht steht er im Spruchkopf -- und der LdF
 *     musste ihn danach von Hand nachtragen.
 *   * Das Verfasserzeichen stand dem Fernmelder offen. Es gehoert dem, der
 *     die Nachricht abfasst; der Fernmelder nimmt sie nur auf.
 *   * Die Abfassungszeit ebenso, und sie war beim Eingang sogar Pflicht.
 *
 * Die Abfassungszeit teilte sich ihren Zugriffsindex mit Betreff und
 * Nachrichtentext. Solange sie das tat, liess sie sich nicht einzeln
 * schliessen, ohne den Inhalt mit zu schliessen -- und der Inhalt ist genau
 * das, was der Fernmelder beim Eingang aufschreibt. Sie hat deshalb einen
 * eigenen Index bekommen, 18, hinter der gedruckten Zaehlung.
 *
 * Geprueft wird die ganze Matrix, nicht die drei geaenderten Felder: Eine
 * Umstellung an der Zugriffstabelle kann einer anderen Station still etwas
 * nehmen, was sie hatte, und das faellt sonst erst im Einsatz auf.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/nv_field_numbers.php';

$previousDirectory = getcwd();
if (!is_string($previousDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
    require_once $root . '/4fach/vali_data.php';
    require_once $root . '/4fach/4fachform.php';
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

/**
 * Die Freigaben einer Station, als Menge gedruckter Feldnummern.
 *
 * Gebaut wird ohne Konstruktor: Der laedt eine Nachricht aus der Datenbank,
 * und die Zugriffstabelle haengt an keiner. Sie haengt am Arbeitsschritt.
 *
 * @return list<int>
 */
$freigaben = static function (string $task, array $formdata = []): array {
    $spiegel = new ReflectionClass('nachrichten4fach');
    $seite = $spiegel->newInstanceWithoutConstructor();
    $seite->task = $task;
    $seite->formdata = $formdata;
    $seite->feldbgcolor();
    $seite->get_access_by_task();
    $offen = [];
    foreach (array_keys(estab_nv_field_map()) as $gedruckt) {
        if ($seite->feld[estab_nv_access_index($gedruckt)] ?? false) {
            $offen[] = $gedruckt;
        }
    }
    return $offen;
};

/*
 * Die Matrix. Jede Zeile ist eine Station, jede Zahl ein gedrucktes Feld des
 * Vordrucks. Wer sie aendert, aendert eine Zustaendigkeit -- und muss sagen,
 * welche und warum.
 */
$erwartet = [
    // Der Fernmelder nimmt eine eingehende Nachricht auf: das tatsaechlich
    // benutzte Mittel, den Aufnahmevermerk, den Rufnamen der Gegenstelle und
    // den ganzen Nachrichtenteil. Nicht die Gespraechsnotiz (die ist ein
    // eigener Vorgang), nicht das Verfasserzeichen und nicht die
    // Abfassungszeit -- beide gehoeren dem, der die Nachricht abgefasst hat.
    // Der Fernmelder nimmt eine eingehende Nachricht auf: das tatsaechlich
    // benutzte Mittel (1), den Aufnahmevermerk (2), den Rufnamen der
    // Gegenstelle (6) und den Nachrichtenteil samt Absender (15).
    // Nicht die Gespraechsnotiz (12) -- die ist ein eigener Vorgang --,
    // nicht die Abfassungszeit (16) und nicht das Verfasserzeichen (17).
    'FM-Eingang' => [1, 2, 6, 8, 9, 10, 11, 13, 14, 15],
    'FM-Eingang_Anhang' => [1, 2, 6, 8, 9, 10, 11, 13, 14, 15],
    // Der LdF bestaetigt oder berichtigt Annahmevermerk und Absender.
    'LdF-Eingang' => [3, 15],
    // Beim Ausgang disponiert er zusaetzlich Gegenstelle und Beforderungsweg.
    'LdF-Ausgang' => [3, 6, 7],
    // Die Weitergabe ist der Befoerderungsvermerk, sonst nichts.
    'FM-Ausgang' => [4],
    // Der Verfasser schreibt den Nachrichtenteil, traegt die Abfassungszeit
    // ein (16) und legt den Verteiler fest (19). Absender und
    // Verfasserzeichen kommen aus Anmeldung und Einrichtung.
    'Stab_schreiben' => [8, 9, 10, 11, 12, 13, 14, 16, 19],
    // Eine zurueckgegebene Nachricht bleibt eine ausgehende: Sie laesst sich
    // nicht nachtraeglich zur Gespraechsnotiz (12) erklaeren.
    'Stab_korrigieren' => [8, 9, 10, 11, 13, 14, 16, 19],
    // Lesen ist lesen.
    'Stab_lesen' => [],
    // Der Sichter quittiert (18) und vermerkt (20).
    'Stab_sichten' => [18, 20],
    // Die Gespraechsnotiz haelt ein gefuehrtes Gespraech fest; wer sie
    // aufnimmt, ist ihr Verfasser und traegt deshalb auch die
    // Abfassungszeit (16) ein.
    'Stab_gesprnoti' => [1, 2, 8, 9, 10, 11, 12, 13, 14, 16, 19, 20],
];

foreach ($erwartet as $task => $felder) {
    $ist = $freigaben($task);
    $assert(
        $ist === $felder,
        'Die Station ' . $task . ' gibt die Felder [' . implode(', ', $ist)
            . '] frei, erwartet sind [' . implode(', ', $felder) . ']. '
            . 'Zuviel: [' . implode(', ', array_diff($ist, $felder))
            . '], zuwenig: [' . implode(', ', array_diff($felder, $ist)) . '].'
    );
}

// Die eingehende Sichtung nimmt den Verteiler dazu.
$assert(
    $freigaben('Stab_sichten', ['04_richtung' => 'E']) === [18, 19, 20],
    'Die Sichtung einer eingehenden Nachricht verteilt sie nicht mehr.'
);

/* --- Und die drei Punkte einzeln benannt --- */

$absender = 15;
$zeichen = 17;
$abfassungszeit = 16;

$eingang = $freigaben('FM-Eingang');
$assert(
    in_array($absender, $eingang, true),
    'Der Fernmelder darf den Absender nicht ausfuellen. Er kennt ihn: Bei '
        . 'einer eingehenden Nachricht steht er im Spruchkopf.'
);
$assert(
    !in_array($zeichen, $eingang, true),
    'Der Fernmelder fuellt das Verfasserzeichen. Es gehoert dem, der die '
        . 'Nachricht abfasst.'
);
$assert(
    !in_array($abfassungszeit, $eingang, true),
    'Der Fernmelder fuellt die Abfassungszeit. Sie gehoert dem Verfasser.'
);

// Der Inhalt bleibt ihm -- das ist der Punkt der ganzen Uebung.
foreach ([13, 14] as $inhalt) {
    $assert(
        in_array($inhalt, $eingang, true),
        'Der Fernmelder kann das gedruckte Feld ' . $inhalt . ' nicht mehr '
            . 'ausfuellen. Betreff und Nachrichtentext sind genau das, was '
            . 'er beim Eingang aufschreibt.'
    );
}

/* --- Was gesperrt ist, darf nicht Pflicht sein --- */

$spiegel = new ReflectionClass('nachrichten4fach');
$seite = $spiegel->newInstanceWithoutConstructor();
$seite->task = 'FM-Eingang';
$pflicht = $seite->official_message_required_fields();
// Dafuer musste die Abfassungszeit ihren eigenen Zugriffsindex bekommen.
$assert(
    estab_nv_access_index($abfassungszeit) !== estab_nv_access_index(13)
        && estab_nv_access_index($abfassungszeit) !== estab_nv_access_index(14),
    'Die Abfassungszeit teilt sich ihren Zugriffsindex wieder mit Betreff '
        . 'oder Nachrichtentext. Dann laesst sie sich nicht einzeln '
        . 'schliessen, ohne den Inhalt mit zu schliessen -- und den schreibt '
        . 'der Fernmelder beim Eingang auf.'
);

foreach (['12_abfzeit' => $abfassungszeit, '14_zeichen' => $zeichen] as $name => $nummer) {
    $assert(
        !in_array($name, $pflicht, true),
        'Der Eingang verlangt ' . $name . ' als Pflichtangabe, gibt das Feld '
            . $nummer . ' aber nicht frei. Der Arbeitsschritt liesse sich '
            . 'dann nicht abschliessen.'
    );
}

printf(
    "Feldfreigabe: OK (%d assertions, %d Stationen)\n",
    $assertions,
    count($erwartet)
);
