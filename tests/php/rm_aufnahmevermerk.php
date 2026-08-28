<?php

declare(strict_types=1);

/**
 * Der Aufnahmevermerk traegt seinen Tag.
 *
 * Ein Vermerk auf dem Nachrichtenvordruck besteht aus Datum, Uhrzeit und
 * Handzeichen. Die drei Zellen stehen nebeneinander und werden aus einem
 * gemeinsamen Feld gefuellt. Vorbelegt war dieses Feld mit date("Hi") --
 * Stunde und Minute, sonst nichts. Die Datumszelle blieb leer, obwohl sie
 * mit einem Pflichtstrich als auszufuellen gekennzeichnet ist.
 *
 * Das faellt am selben Tag nicht auf und am naechsten sehr wohl: "2110" sagt
 * nicht, welcher Tag gemeint ist, und ein Einsatz laeuft ueber Mitternacht.
 * Eine Nachricht, die im Nachweis nur eine Uhrzeit traegt, laesst sich nicht
 * mehr sicher einordnen.
 *
 * Vorbelegt wird jetzt die taktische Zeitgruppe -- TThhmmMMMyyyy, dieselbe
 * Schreibweise, die Annahme- und Befoerderungsvermerk aus der Datenbank
 * bekommen. Sie bleibt frei korrigierbar; sie ist ein Vorschlag, keine
 * Feststellung.
 *
 * Der Annahmevermerk (Feld 3, `02_zeit`) bleibt eine reine Uhrzeit. Das ist
 * keine Auslassung, sondern seine Bauart: Der Vordruck stellt ihn
 * ausdruecklich als time-only dar.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

$previousDirectory = getcwd();
if (!is_string($previousDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
    require_once $root . '/4fach/vali_data.php';
} finally {
    chdir($previousDirectory);
}
require_once $root . '/app/permission_mode.php';
require_once $root . '/app/message_priority.php';
require_once $root . '/4fach/official_message_form.php';

final class AufnahmevermerkFixture
{
    use EstabOfficialMessageFormView;

    /** @var array<string,mixed> */
    public array $formdata = [];

    /** @var array<string,bool> */
    public array $errorselect = [];

    /** @var array<int,bool> */
    public array $feld = [];

    public string $task = 'FM-Eingang';

    public function safe_message_value(string $field): string
    {
        return estab_message_html((string) ($this->formdata[$field] ?? ''));
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$fixture = new AufnahmevermerkFixture();

/* --- Die Vorbelegung ist eine Zeitgruppe, keine blosse Uhrzeit --- */

$quelle = file_get_contents($root . '/4fach/4fachform.php');
$assert(is_string($quelle), '4fachform.php ist nicht lesbar.');
$quelle = (string) $quelle;

if (preg_match(
    // `] =` ohne folgendes Gleichheitszeichen: die Zuweisung, nicht der
    // Vergleich zwei Zeilen darueber.
    '~\$editableTimestampField !== .{0,2000}?\$editableTimestampField\] =(?!=)\s*([^;]+);~s',
    $quelle,
    $treffer
) !== 1) {
    throw new RuntimeException(
        'Die Vorbelegung der bearbeitbaren Zeitstempel ist nicht mehr '
            . 'auffindbar.'
    );
}
$vorbelegung = $treffer[1];
$assert(
    !preg_match('~date\s*\(\s*"Hi"\s*\)~', $vorbelegung),
    estab_dv_requirement(
        'NV-02-AUFNAHMEVERMERK',
        'Der Aufnahmevermerk wird weiterhin mit date("Hi") vorbelegt -- nur '
            . 'Stunde und Minute. Die Datumszelle bleibt damit leer, obwohl '
            . 'sie als Pflichtangabe gekennzeichnet ist.'
    )
);
$assert(
    str_contains($vorbelegung, 'konv_datetime_taktime ('),
    estab_dv_requirement(
        'NV-02-AUFNAHMEVERMERK',
        'Die Vorbelegung kommt nicht aus der taktischen Zeitgruppe: '
            . trim(preg_replace('~\s+~', ' ', $vorbelegung) ?? '')
    )
);

/* --- Und sie zerfaellt in beide Zellen --- */

/*
 * Die Zeitgruppe hilft nur, wenn die Anzeige sie in Datum und Uhrzeit
 * trennt. Sonst stuende der ganze Block in einer Zelle, und die Sache waere
 * schlimmer als vorher.
 */
// konv_datetime_taktime() laedt die Monatsnamen ueber einen relativen
// Include; sie ist deshalb nur aus dem Laufzeitverzeichnis heraus aufrufbar.
$gruppe = (static function () use ($root): string {
    $zuvor = getcwd();
    if (!is_string($zuvor) || !chdir($root . '/4fach')) {
        throw new RuntimeException('Cannot enter the message runtime directory');
    }
    try {
        return konv_datetime_taktime('2026-08-28 21:10:00');
    } finally {
        chdir($zuvor);
    }
})();
$assert(
    preg_match('~\A\d{2}\d{4}[[:alpha:]ÄÖÜäöü]{3}\d{4}\z~u', $gruppe) === 1,
    'Die taktische Zeitgruppe hat nicht die Form TThhmmMMMyyyy: ' . $gruppe
);

$teile = $fixture->official_message_stamp_parts($gruppe, false);
$assert(
    $teile['time'] === '2110',
    estab_dv_requirement(
        'NV-02-AUFNAHMEVERMERK',
        'Die Uhrzeitzelle des Vermerks zeigt "' . $teile['time']
            . '" statt "2110".'
    )
);
$assert(
    str_starts_with($teile['date'], '28') && strlen($teile['date']) === 9,
    estab_dv_requirement(
        'NV-02-AUFNAHMEVERMERK',
        'Die Datumszelle des Vermerks zeigt "' . $teile['date']
            . '" statt des Tages mit Monat und Jahr.'
    )
);

// Beisst die Pruefung? Der alte Wert darf die Datumszelle nicht fuellen.
$alt = $fixture->official_message_stamp_parts('2110', false);
$assert(
    $alt['date'] === '' && $alt['time'] === '2110',
    'Die Zerlegung erfindet fuer eine blosse Uhrzeit ein Datum; damit waere '
        . 'der Befund oben keiner.'
);

/* --- Der Annahmevermerk bleibt eine Uhrzeit --- */

$nurZeit = $fixture->official_message_stamp_parts($gruppe, true);
$assert(
    $nurZeit['date'] === '' && $nurZeit['time'] === '2110',
    estab_dv_requirement(
        'NV-03-ANNAHMEVERMERK',
        'Der Annahmevermerk zeigt jetzt ein Datum; der Vordruck stellt ihn '
            . 'ausdruecklich als reine Uhrzeit dar.'
    )
);
$assert(
    str_contains($quelle, '"LdF-Eingang" => "02_zeit"')
        && str_contains($quelle, '"FM-Eingang" => "01_datum"')
        && str_contains($quelle, '"FM-Ausgang" => "03_datum"'),
    'Die Zuordnung der bearbeitbaren Zeitstempel zu den Arbeitsschritten hat '
        . 'sich geaendert; dieser Test muss mit.'
);

printf("Aufnahmevermerk: OK (%d assertions)\n", $assertions);
