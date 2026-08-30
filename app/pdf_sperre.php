<?php

declare(strict_types=1);

/**
 * Höchstens eine PDF-Ausgabe gleichzeitig.
 *
 * Das Einsatzdossier ist die teuerste Handlung der Anwendung. FPDF hält das
 * ganze Dokument im Speicher -- alle Seiteninhalte im Feld und noch einmal
 * im Ausgabepuffer -- und erreicht gemessen 65 MB Spitze, mit Rasteranlagen
 * rund 90 MB. Der app-Container hat 448 MB: Eine laufende Ausgabe plus elf
 * gewöhnliche Seitenaufbauten passen bequem, drei gleichzeitige Ausgaben
 * nicht. Dann startet der Container neu, mitten im Einsatz.
 *
 * ## Warum nicht warten
 *
 * Die Sperre ist ausdrücklich nicht blockierend. Ein Dossier über einen
 * ganzen Einsatz dauert; wer als zweiter käme, hinge minutenlang an einer
 * Seite, die aussieht, als sei sie abgestürzt. Eine Auskunft „läuft schon,
 * gleich noch einmal" ist ehrlicher als ein Ladebalken ohne Ende.
 *
 * ## Warum eine Datei und kein Zähler
 *
 * Apache läuft im prefork-Modus: Jede Anfrage ist ein eigener Prozess. Eine
 * Variable, ein statisches Feld oder auch ein Sitzungswert sähen davon
 * nichts. `flock` auf einer Datei gilt prozessübergreifend, und das
 * Betriebssystem gibt sie frei, wenn der Prozess endet -- auch dann, wenn
 * er unsanft endet. Genau das ist hier der schwierige Fall: Eine Sperre,
 * die nach einem Abbruch liegen bleibt, sperrt die Ausgabe bis zum Neustart
 * des Containers aus.
 */

/** Die PDF-Ausgabe läuft bereits. */
final class EstabPdfBesetztException extends RuntimeException
{
}

/** Wo die Sperrdatei liegt. */
function estab_pdf_sperre_pfad(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'estab-pdf-ausgabe.lock';
}

/**
 * Eine Handlung unter der PDF-Sperre ausführen.
 *
 * @template T
 * @param callable():T $handlung
 * @return T
 * @throws EstabPdfBesetztException wenn bereits eine Ausgabe läuft
 */
function estab_pdf_sperre_halten(callable $handlung): mixed
{
    $pfad = estab_pdf_sperre_pfad();
    $griff = @fopen($pfad, 'c');
    if ($griff === false) {
        /*
         * Keine Sperrdatei, keine Sperre -- aber auch kein Grund, die
         * Ausgabe zu verweigern. Ein nicht beschreibbares Verzeichnis ist
         * ein Einrichtungsfehler; ihn hier in einen Betriebsfehler zu
         * verwandeln, träfe den Falschen. Die Containergrenze bleibt als
         * zweite Sicherung.
         */
        error_log(
            'eStab PDF-Sperre nicht anlegbar: ' . $pfad
        );
        return $handlung();
    }
    try {
        if (!flock($griff, LOCK_EX | LOCK_NB)) {
            throw new EstabPdfBesetztException(
                'Ein Einsatzdossier wird bereits erstellt. Bitte warten Sie, '
                    . 'bis es fertig ist, und versuchen Sie es dann erneut.'
            );
        }
        try {
            return $handlung();
        } finally {
            flock($griff, LOCK_UN);
        }
    } finally {
        fclose($griff);
    }
}
