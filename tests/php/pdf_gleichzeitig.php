<?php

declare(strict_types=1);

/**
 * Höchstens eine PDF-Ausgabe gleichzeitig.
 *
 * Das Einsatzdossier ist die teuerste Handlung der Anwendung: FPDF hält das
 * ganze Dokument im Speicher -- alle Seiteninhalte im Feld und noch einmal
 * im Ausgabepuffer -- und erreicht dabei gemessen 65 MB Spitze, mit
 * Rasteranlagen rund 90 MB.
 *
 * Der app-Container hat 448 MB. Eine laufende Ausgabe plus elf gewöhnliche
 * Seitenaufbauten passen bequem hinein. Drei gleichzeitige Ausgaben nicht:
 * Dann startet der Container neu, und zwar mitten im Einsatz.
 *
 * Deshalb eine Sperre. Sie ist nicht blockierend: Wer als zweiter kommt,
 * bekommt sofort eine verständliche Auskunft statt einer Wartezeit, deren
 * Ende niemand kennt. Ein Dossier über einen ganzen Einsatz dauert; eine
 * Seite, die minutenlang lädt, sieht aus wie ein Fehler.
 *
 * Geprüft wird an der Mechanik, nicht am Ablauf: dass die Sperre
 * ausschließlich und ohne Warten genommen wird, dass ein zweiter Versuch
 * scheitert, solange der erste läuft, und -- das ist der Teil, der im
 * Betrieb wehtut -- dass sie auch dann wieder freigegeben wird, wenn die
 * Ausgabe mit einem Fehler abbricht. Eine Sperre, die nach einem Fehler
 * liegen bleibt, sperrt die Ausgabe bis zum Neustart des Containers aus.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/pdf_sperre.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// Eine Sperre wird genommen und wieder freigegeben.
$ergebnis = estab_pdf_sperre_halten(static fn (): string => 'fertig');
$assert(
    $ergebnis === 'fertig',
    'Die Sperre gibt das Ergebnis der Ausgabe nicht zurueck.'
);

// Und danach ist sie frei: ein zweiter Lauf geht durch.
$zweiter = estab_pdf_sperre_halten(static fn (): string => 'auch fertig');
$assert(
    $zweiter === 'auch fertig',
    'Nach einer beendeten Ausgabe bleibt die Sperre liegen.'
);

// Bricht die Ausgabe ab, wird die Sperre trotzdem frei.
$geworfen = null;
try {
    estab_pdf_sperre_halten(static function (): string {
        throw new RuntimeException('Die Ausgabe bricht ab.');
    });
} catch (Throwable $fehler) {
    $geworfen = $fehler;
}
$assert(
    $geworfen instanceof RuntimeException
        && $geworfen->getMessage() === 'Die Ausgabe bricht ab.',
    'Ein Fehler in der Ausgabe wird verschluckt statt weitergereicht.'
);
$assert(
    estab_pdf_sperre_halten(static fn (): string => 'wieder frei')
        === 'wieder frei',
    'Nach einem Fehler bleibt die Sperre liegen. Die PDF-Ausgabe waere bis '
        . 'zum Neustart des Containers gesperrt.'
);

// Zwei gleichzeitige Ausgaben: die zweite wird abgewiesen, nicht wartend
// gehalten. Nachgestellt, indem die zweite *innerhalb* der ersten laeuft.
$innenFehler = null;
estab_pdf_sperre_halten(static function () use (&$innenFehler): string {
    try {
        estab_pdf_sperre_halten(static fn (): string => 'darf nicht passieren');
    } catch (Throwable $fehler) {
        $innenFehler = $fehler;
    }
    return 'aussen';
});
$assert(
    $innenFehler instanceof EstabPdfBesetztException,
    'Eine zweite gleichzeitige PDF-Ausgabe wird nicht abgewiesen. Drei davon '
        . 'sprengen die Containergrenze von 448 MB.'
);
$assert(
    str_contains(
        (string) $innenFehler?->getMessage(),
        'wird bereits erstellt'
    ),
    'Die Abweisung sagt nicht, was los ist. Der Bediener sieht einen Fehler, '
        . 'wo er "gleich noch einmal versuchen" lesen muesste: '
        . (string) $innenFehler?->getMessage()
);

// Die Sperre wartet nicht. Ein blockierender Aufruf wuerde die zweite
// Anfrage bis zum Ende der ersten haengen lassen -- bei einem Dossier ueber
// einen ganzen Einsatz sind das Minuten.
$quelle = file_get_contents($root . '/app/pdf_sperre.php');
$assert(is_string($quelle), 'app/pdf_sperre.php ist nicht lesbar.');
$assert(
    str_contains((string) $quelle, 'LOCK_EX | LOCK_NB'),
    'Die Sperre wird nicht ausschliesslich und ohne Warten genommen.'
);

// Und der Ausgabepfad benutzt sie wirklich.
$export = file_get_contents($root . '/app/incident_export.php');
$assert(is_string($export), 'app/incident_export.php ist nicht lesbar.');
$assert(
    str_contains((string) $export, 'estab_pdf_sperre_halten('),
    'Der PDF-Ausgabepfad nimmt die Sperre nicht. Sie laege im Baum, ohne im '
        . 'Betrieb zu wirken.'
);

/*
 * Und der Bediener sieht den Satz, nicht "Details stehen im Container-Log".
 *
 * Ohne einen eigenen Zweig faele die Abweisung in den Sammelzweig fuer
 * unerwartete Fehler -- 500 und eine Meldung, die niemandem hilft. Es ist
 * aber kein Fehler: Es laeuft schon eines, gleich noch einmal.
 */
$oberflaeche = file_get_contents($root . '/4fadm/incident_export.php');
$assert(is_string($oberflaeche), '4fadm/incident_export.php ist nicht lesbar.');
$oberflaeche = (string) $oberflaeche;
$besetztZweig = strpos($oberflaeche, 'catch (EstabPdfBesetztException');
/*
 * Der Sammelzweig, der zaehlt, ist der *aeussere* -- der, der die Meldung
 * "Details stehen im Container-Log" setzt. Weiter oben steht ein zweiter,
 * der nur die Transaktion zuruecknimmt und den Fehler weiterreicht; der
 * verschluckt nichts.
 */
$sammelZweig = strpos(
    $oberflaeche,
    'Das Einsatzdossier konnte nicht erstellt werden.'
);
$assert(
    is_int($besetztZweig),
    'Die Ausgabeseite faengt die Abweisung nicht eigens ab; der Bediener '
        . 'saehe einen 500er statt einer Auskunft.'
);
$assert(
    is_int($sammelZweig) && $besetztZweig < $sammelZweig,
    'Der Sammelzweig steht vor der Abweisung und faengt sie weg.'
);
$assert(
    preg_match(
        '~catch \(EstabPdfBesetztException.*?http_response_code\(503\)~s',
        $oberflaeche
    ) === 1
        && preg_match(
            '~catch \(EstabPdfBesetztException.*?Retry-After~s',
            $oberflaeche
        ) === 1,
    'Die Abweisung antwortet nicht mit 503 und Retry-After. 503 heisst '
        . '"gleich wieder da"; 500 heisst "kaputt", und das ist es nicht.'
);
$assert(
    preg_match(
        '~catch \(EstabPdfBesetztException.*?\$error = \$exception->getMessage\(\);~s',
        $oberflaeche
    ) === 1,
    'Die Ausgabeseite zeigt den Satz der Abweisung nicht an.'
);

printf("PDF gleichzeitig: OK (%d assertions)\n", $assertions);
