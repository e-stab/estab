<?php

declare(strict_types=1);

/**
 * Passen Endung und Inhalt einer Anlage zusammen -- und lässt sie sich
 * darstellen?
 *
 * Das Einsatzdossier bildet Anlagen auf Seiten ab: Bilder als Raster, PDF
 * seitenweise, Text und E-Mail als Fließtext. Alles andere -- Archive,
 * Office-Dateien, Videos -- bekommt eine ehrliche Hinweisseite: Die Datei
 * liegt bytegleich im Dossier, aber sie lässt sich nicht verlässlich auf
 * statische Seiten abbilden.
 *
 * Dazwischen liegt ein dritter Fall, und der ist der gefährliche: Eine Datei
 * heißt `.pdf`, ihre Bytes sind aber Text. Oder sie heißt `.jpg` und ist eine
 * ZIP-Datei. Wer so etwas darstellt, zeigt einen Inhalt, den der Dateiname
 * nicht ankündigt -- und ein Dossier ist ein Nachweis, kein Vorschaufenster.
 *
 * ## Warum diese Regel an einer Stelle steht
 *
 * Sie galt bisher nur im Dossier, und dort erst beim Erzeugen. Eine Datei,
 * die den Widerspruch trägt, kam durch den Upload, lag monatelang im Einsatz
 * und liess das Dossier scheitern, wenn es gebraucht wurde -- mit einer
 * Meldung, die nicht einmal sagte, welche Anlage gemeint ist.
 *
 * Jetzt prüft schon der Upload mit demselben Maßstab. Was hineinkommt, lässt
 * sich später verarbeiten; was nicht, wird abgewiesen, solange noch jemand
 * davorsitzt und es richtigstellen kann.
 */

/** Die Anlage lässt sich im Dossier darstellen. */
const ESTAB_ANLAGE_DARSTELLBAR = 'darstellbar';

/** Die Anlage bekommt eine Hinweisseite -- Archiv, Office, Video. */
const ESTAB_ANLAGE_HINWEIS = 'hinweis';

/** Endung und Inhalt widersprechen einander. */
const ESTAB_ANLAGE_WIDERSPRUCH = 'widerspruch';

/**
 * Welche Inhaltstypen zu welcher Endung gehören.
 *
 * Die Tabelle ist die des Dossiers, Wort für Wort: Sie entscheidet dort, ob
 * eine Anlage gerastert, seitenweise gesetzt oder als Text gezeigt wird.
 *
 * @return array<string, list<string>>
 */
function estab_anlage_darstellbare_paare(): array
{
    return [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'eml' => ['message/rfc822'],
    ];
}

/**
 * Jeder Inhaltstyp, den das Dossier darstellen kann.
 *
 * @return list<string>
 */
function estab_anlage_darstellbare_inhaltstypen(): array
{
    $typen = [];
    foreach (estab_anlage_darstellbare_paare() as $liste) {
        foreach ($liste as $typ) {
            $typen[$typ] = true;
        }
    }
    return array_keys($typen);
}

/**
 * Der Befund zu einer Anlage.
 *
 * Ein Widerspruch liegt vor, wenn **eine** der beiden Seiten darstellbar ist
 * und die andere nicht dazu passt. Sind beide unauffällig -- eine ZIP-Datei
 * mit ZIP-Bytes -- ist das kein Widerspruch, sondern ein Hinweisfall.
 */
function estab_anlage_befund(string $endung, string $inhaltstyp): string
{
    $endung = strtolower(ltrim(trim($endung), '.'));
    $inhaltstyp = strtolower(trim($inhaltstyp));
    $paare = estab_anlage_darstellbare_paare();
    $endungDarstellbar = array_key_exists($endung, $paare);
    if ($endungDarstellbar && in_array($inhaltstyp, $paare[$endung], true)) {
        return ESTAB_ANLAGE_DARSTELLBAR;
    }
    $typDarstellbar = in_array(
        $inhaltstyp,
        estab_anlage_darstellbare_inhaltstypen(),
        true
    );
    if ($endungDarstellbar || $typDarstellbar) {
        return ESTAB_ANLAGE_WIDERSPRUCH;
    }
    return ESTAB_ANLAGE_HINWEIS;
}

/**
 * Der Inhaltstyp einer Datei, so wie ihn das Dossier später sieht.
 *
 * Dieselbe Erkennung wie beim Einbetten: Gelesen werden die Bytes, nicht der
 * Name. Was `finfo` nicht einordnen kann, gilt als `application/octet-stream`
 * -- also als etwas, das keine Endung darstellbar macht.
 */
function estab_anlage_inhaltstyp_der_datei(string $pfad): string
{
    if (!class_exists('finfo') || !is_file($pfad)) {
        return 'application/octet-stream';
    }
    $erkannt = (new finfo(FILEINFO_MIME_TYPE))->file($pfad);
    if (
        !is_string($erkannt)
        || preg_match(
            '/\A[a-z0-9][a-z0-9!#$&^_.+-]*\/'
                . '[a-z0-9][a-z0-9!#$&^_.+-]*\z/DiD',
            $erkannt
        ) !== 1
    ) {
        return 'application/octet-stream';
    }
    return strtolower($erkannt);
}

/**
 * Der Satz, der einen Widerspruch benennt -- mit Namen, Endung und Bytes.
 *
 * Ohne diese drei Angaben ist die Meldung wertlos: Ein Einsatz hat vierzig
 * Anlagen, und „eine darstellbare Anlage" ist keine davon.
 */
function estab_anlage_widerspruch_satz(
    string $anzeigename,
    string $endung,
    string $inhaltstyp
): string {
    $endung = strtolower(ltrim(trim($endung), '.'));
    $paare = estab_anlage_darstellbare_paare();
    $erwartet = $paare[$endung] ?? [];
    $satz = 'Die Anlage „' . $anzeigename . '" trägt die Endung .' . $endung
        . ', ihr Inhalt ist aber ' . $inhaltstyp . '.';
    if ($erwartet !== []) {
        $satz .= ' Zu .' . $endung . ' gehört '
            . implode(' oder ', $erwartet) . '.';
    } else {
        $satz .= ' Zu ' . $inhaltstyp . ' gehört die Endung '
            . implode(
                ' oder ',
                array_keys(array_filter(
                    $paare,
                    static fn (array $liste): bool
                        => in_array($inhaltstyp, $liste, true)
                ))
            ) . '.';
    }
    return $satz . ' Eine Datei, deren Name etwas anderes ankündigt als ihr '
        . 'Inhalt, wird nicht dargestellt -- ein Dossier ist ein Nachweis.';
}
