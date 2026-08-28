<?php

declare(strict_types=1);

/**
 * Die Nachweisung Eingang und Ausgang -- aus dem Tabellenbauteil.
 *
 * Die Nachweisung ist die Liste, an der der Fernmeldebetrieb nachweist, was
 * er aufgenommen und was er befördert hat. Sie hatte weder Suche noch Filter:
 * Wer einen bestimmten Nachweis suchte, blätterte.
 *
 * > „Hier sollte ein allgemeines Konzept für Tabellen entwickelt werden."
 *
 * Sie ist deshalb die erste Tabelle aus dem Bauteil (app/tabelle.php). Was
 * hier steht, ist allein die Frage, **was** in den Spalten steht -- alles
 * andere kommt von dort.
 *
 * ## Warum alle Zeilen geladen werden
 *
 * Die alte Fassung holte eine Seite je Aufruf (`LIMIT`/`OFFSET`) und zählte
 * daneben. Das geht nicht mehr: Wer über alle Nachweise suchen und sortieren
 * will, muss sie haben -- eine Sortierung über eine Seite ist keine
 * Sortierung, sondern eine Umordnung von fünfundzwanzig zufälligen Zeilen.
 *
 * Die Zahl ist überschaubar: Eine Nachweisung führt die Nachrichten *eines*
 * Einsatzes. Sollte ein Einsatz sie sprengen, ist die Antwort nicht, still
 * abzuschneiden, sondern das Bauteil sieben zu lassen, bevor es zählt.
 */

require_once __DIR__ . '/tabelle.php';
require_once __DIR__ . '/message_list.php';
require_once __DIR__ . '/message_repository.php';
require_once __DIR__ . '/message_transport.php';
require_once __DIR__ . '/message_priority.php';
require_once __DIR__ . '/datetime.php';

/**
 * Die Spalten der Nachweisung.
 *
 * `$mitRichtung` nur in der gemeinsamen Liste: In einer reinen Eingangsliste
 * steht in jeder Zeile dasselbe, und eine Spalte, in der überall dasselbe
 * steht, kostet Breite und sagt nichts.
 *
 * @return list<array<string,mixed>>
 */
function estab_nachweisung_spalten(bool $mitRichtung): array
{
    $spalten = [
        ['schluessel' => 'vorrang', 'kopf' => 'Vorrang', 'breite' => 9,
            'sortierbar' => true, 'suchbar' => false, 'art' => 'vorrang',
            'filter' => ['Sofort', 'Blitz', 'Staatsnot'],
            'filtername' => 'Alle Vorrangstufen'],
    ];
    if ($mitRichtung) {
        $spalten[] = ['schluessel' => 'richtung', 'kopf' => 'E/A', 'breite' => 6,
            'sortierbar' => true, 'suchbar' => false, 'art' => 'text',
            'filter' => ['E', 'A'], 'filtername' => 'Ein- und Ausgang'];
    }
    $spalten[] = ['schluessel' => 'nachweis', 'kopf' => 'TBB-Nachweis',
        'breite' => 13, 'sortierbar' => true, 'suchbar' => true, 'art' => 'zahl'];
    $spalten[] = ['schluessel' => 'gegenueber', 'kopf' => 'Von/An',
        'breite' => 16, 'sortierbar' => true, 'suchbar' => true, 'art' => 'text'];
    $spalten[] = ['schluessel' => 'abfasszeit', 'kopf' => 'Abfasszeit',
        'breite' => 11, 'sortierbar' => true, 'suchbar' => false, 'art' => 'zeit'];
    $spalten[] = ['schluessel' => 'medium', 'kopf' => 'Mittel', 'breite' => 11,
        'sortierbar' => true, 'suchbar' => false, 'art' => 'text',
        'filter' => estab_nachweisung_mittel(), 'filtername' => 'Alle Mittel'];
    $spalten[] = ['schluessel' => 'inhalt', 'kopf' => 'Inhalt',
        'breite' => $mitRichtung ? 34 : 40,
        'sortierbar' => false, 'suchbar' => true, 'art' => 'text'];
    return $spalten;
}

/**
 * Die Übermittlungsmittel, die als Filter angeboten werden.
 *
 * @return list<string>
 */
function estab_nachweisung_mittel(): array
{
    return ['Funk', 'Telefon', 'Telefax', 'DFÜ', 'Kurier/Melder'];
}

/**
 * Eine Datenbankzeile in eine Tabellenzeile übersetzen.
 *
 * @param array<string,mixed> $zeile
 * @return array<string,string>
 */
function estab_nachweisung_zeile(array $zeile): array
{
    $richtung = (string) ($zeile['04_richtung'] ?? '');
    // Beim Ausgang steht die Anschrift für das Gegenüber, beim Eingang die
    // absendende Einheit. Eine Spalte, zwei Herkünfte -- und deshalb ein
    // Kopf, der beides trägt: „Von/An".
    $gegenueber = $richtung === 'A'
        ? (string) ($zeile['10_anschrift'] ?? '')
        : (string) ($zeile['13_abseinheit'] ?? '');
    $abfasszeit = (string) ($zeile['12_abfzeit'] ?? '');
    if ($abfasszeit !== '') {
        $teile = estab_datetime_parts($abfasszeit);
        $abfasszeit = (string) ($teile['stak'] ?? '');
    }
    $anhaenge = count(estab_message_list_attachment_tokens($zeile['12_anhang'] ?? null));
    // Reiner Text, keine Auszeichnung: Das Bauteil maskiert, kürzt auf zwei
    // Zeilen und klappt den Rest auf. Eine Seite, die fertiges Markup
    // liefert, umgeht die Maskierung.
    $inhalt = estab_message_plain_text($zeile['12_inhalt'] ?? '');
    if ($anhaenge > 0) {
        $inhalt = trim($inhalt) . ' · '
            . estab_message_list_attachment_label($anhaenge);
    }
    return [
        'lfd' => (string) ($zeile['00_lfd'] ?? ''),
        'vorrang' => estab_message_priority_label($zeile['09_vorrangstufe'] ?? ''),
        'richtung' => $richtung,
        'nachweis' => estab_message_list_tbb_evidence_label($zeile),
        'gegenueber' => $gegenueber,
        'abfasszeit' => $abfasszeit,
        'medium' => estab_message_medium_text($zeile['01_medium'] ?? ''),
        'inhalt' => $inhalt,
    ];
}

/**
 * Alle Nachweiszeilen eines Einsatzes laden.
 *
 * `$richtung` ist 'E', 'A' oder '' für beides.
 *
 * @return list<array<string,string>>
 */
function estab_nachweisung_zeilen(
    array $databaseConfig,
    int $incidentId,
    string $richtung
): array {
    global $conf_4f_tbl;
    $tabelle = $conf_4f_tbl['nachrichten'];
    $bedingung = ' FROM `' . $tabelle . '` AS m WHERE m.`einsatz_id` = ?';
    $werte = [$incidentId];
    if ($richtung !== '') {
        $bedingung .= ' AND m.`04_richtung` = ?';
        $werte[] = $richtung;
    }
    // Die Grundordnung: nach Nachweisnummer, dann nach laufender Nummer.
    // Sie steht, solange niemand sortiert hat -- und sie ist eine Aussage,
    // keine Zufaelligkeit.
    $abfrage = 'SELECT m.`00_lfd`,m.`01_medium`,m.`09_vorrangstufe`,'
        . 'm.`04_richtung`,'
        . estab_message_list_tbb_number_select_sql('m') . ','
        . 'm.`10_anschrift`,m.`12_abfzeit`,m.`12_inhalt`,'
        . 'm.`12_anhang`,m.`13_abseinheit`,m.`x01_abschluss`'
        . $bedingung
        . ' ORDER BY COALESCE(' . estab_message_list_tbb_number_sql('m')
        . ', 4294967296) ASC, m.`00_lfd` ASC';
    $verbindung = estab_message_connect($databaseConfig);
    try {
        $roh = estab_message_query_rows($verbindung, $abfrage, $werte);
    } finally {
        estab_auth_close($verbindung);
    }
    return array_map('estab_nachweisung_zeile', $roh);
}

/**
 * Eine Nachweisung ausgeben.
 *
 * @param array<string,mixed> $databaseConfig
 */
function estab_nachweisung_ausgeben(
    array $databaseConfig,
    int $incidentId,
    string $richtung,
    string $titel
): void {
    $mitRichtung = $richtung === '';
    $id = 'nachweisung-' . ($richtung === 'E'
        ? 'eingang'
        : ($richtung === 'A' ? 'ausgang' : 'alle'));
    echo '<h2 class="estab-nachweisung-titel">'
        . estab_message_html($titel) . "</h2>\n";
    estab_tabelle_ausgeben([
        'id' => $id,
        'spalten' => estab_nachweisung_spalten($mitRichtung),
        'zeilen' => estab_nachweisung_zeilen($databaseConfig, $incidentId, $richtung),
        'leer' => 'Kein Nachweis entspricht den gesetzten Filtern.',
    ]);
}
