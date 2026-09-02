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
require_once __DIR__ . '/nv_datetime_group.php';

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
        'breite' => 11, 'sortierbar' => true, 'suchbar' => false, 'art' => 'zeit',
        'zelle' => static fn (array $z): string =>
            estab_message_html((string) ($z['abfasszeit_kurz'] ?? ''))];
        /*
     * Der Wunsch steht in der Anzeige, nicht im Wert -- sonst fiele die
     * Zeile aus dem Filter ihres eigenen Mittels heraus.
     */
    $spalten[] = ['schluessel' => 'mittel', 'kopf' => 'Weg', 'breite' => 13,
        'sortierbar' => true, 'suchbar' => true, 'art' => 'text',
        'filter' => estab_nachweisung_mittel(), 'filtername' => 'Alle Wege',
        'zelle' => static function (array $z): string {
            $text = estab_message_html((string) ($z['mittel'] ?? ''));
            if ($text === '' || ($z['wunsch'] ?? false) !== true) {
                return $text;
            }
            return $text . ' <small>(gewünscht)</small>';
        }];
    $spalten[] = ['schluessel' => 'inhalt', 'kopf' => 'Inhalt',
        'breite' => $mitRichtung ? 34 : 40,
        'sortierbar' => false, 'suchbar' => true, 'art' => 'text'];
    return $spalten;
}

/**
 * Der Weg, über den die Meldung ging -- oder der gewünschte.
 *
 * Der Vordruck trägt zwei Angaben, und sie können auseinanderfallen:
 *
 * - Feld 1 ist beim **Eingang** das Mittel, über das die Meldung
 *   hereinkam -- eine Tatsache. Beim **Ausgang** ist es das Mittel, das
 *   der Verfasser sich wünscht -- eine Bitte an die Fernmeldestelle.
 * - Feld 6 trägt den Beförderungsweg, den die Leitung des
 *   Fernmeldebetriebs tatsächlich gewählt hat. Er steht erst da, wenn
 *   befördert wurde.
 *
 * Ein Nachweis, der beides gleich darstellt, behauptet einen Weg, den es
 * vielleicht nie gab. Der Wunsch wird deshalb als Wunsch gekennzeichnet.
 * Der Fall ist nicht selten: Im Bestand tragen 120 Ausgänge ein
 * Wunschmittel ohne Beförderungsweg.
 *
 * Beim Eingang wird nichts gekennzeichnet -- dort ist Feld 1 die
 * Tatsache, und ein „gewünscht" daneben wäre eine Behauptung über den
 * Absender, die niemand aufgestellt hat.
 */
function estab_nachweisung_weg(array $zeile): string
{
    /*
     * Das Mittel des Weges steht in `06_befwegausw` als Kuerzel; die
     * Wegbeschreibung in `06_befweg` ist freier Text ("Rufgruppe Fuehrung
     * . Gegenverkehr . TMO 310"). Fuer eine Spalte, die sortiert und
     * gefiltert wird, zaehlt das Kuerzel -- die Beschreibung steht im
     * Vordruck.
     */
    $weg = trim((string) ($zeile['06_befwegausw'] ?? ''));
    if ($weg !== '') {
        /*
         * Übersetzt, wenn es ein bekanntes Mittel ist.
         *
         * Nicht über estab_message_transport_text: Die stellt das
         * gewünschte und das gewählte Mittel nebeneinander und lässt das
         * gewählte dabei unuebersetzt ("Fernsprecher · Fu"). Im Nachweis
         * zählt der Weg, über den die Meldung ging.
         *
         * Feld 6 kann auch freien Text tragen -- einen Meldernamen etwa.
         * Dann steht er, wie er dasteht.
         */
        $uebersetzt = estab_message_medium_text($weg);
        return $uebersetzt === '' ? $weg : $uebersetzt;
    }
    return estab_message_medium_text($zeile['01_medium'] ?? '');
}

/**
 * Steht in dieser Zeile ein Wunsch statt eines gegangenen Weges?
 *
 * Der Unterschied gehoert in die Anzeige, nicht in den Wert: Das Bauteil
 * filtert auf Gleichheit. Stuende "Funk (gewuenscht)" in der Zelle, fiele
 * die Zeile aus dem Filter "Funk" heraus -- und ein Fernmelder, der nach
 * Funk filtert, verloere genau die Auftraege, die noch offen sind.
 */
function estab_nachweisung_weg_ist_wunsch(array $zeile): bool
{
    return trim((string) ($zeile['06_befwegausw'] ?? '')) === ''
        && (string) ($zeile['04_richtung'] ?? '') === 'A'
        && estab_message_medium_text($zeile['01_medium'] ?? '') !== '';
}

/**
 * Die Übermittlungsmittel, die als Filter angeboten werden.
 *
 * @return list<string>
 */
function estab_nachweisung_mittel(): array
{
    /*
     * Dieselben Woerter, die in der Spalte stehen.
     *
     * Hier stand einmal eine eigene Liste -- 'Funk', 'Telefon',
     * 'Telefax', 'DFUE', 'Kurier/Melder'. Die Spalte zeigt aber, was
     * estab_message_medium_text liefert: 'Funk', 'Fernsprecher',
     * 'Melder', 'Fax', 'Fernschreiber', 'Datenuebertragung'. Das Bauteil
     * filtert auf Gleichheit; von den fuenf angebotenen Werten traf
     * genau einer je zu. Wer nach 'Telefon' filterte, bekam eine leere
     * Liste und keinen Hinweis, warum.
     *
     * Die Liste wird deshalb aus derselben Uebersetzung gebildet wie die
     * Spalte. Ein zweites Vokabular kann so nicht mehr entstehen.
     */
    $mittel = [];
    foreach (['Fu', 'Fe', 'Me', 'FAX', 'FS', '@'] as $kuerzel) {
        $wort = estab_message_medium_text($kuerzel);
        if ($wort !== '') {
            $mittel[$wort] = true;
        }
    }
    $namen = array_keys($mittel);
    sort($namen);
    return $namen;
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
    /*
     * Der Wert ist die lange taktische Form, die Anzeige die kurze.
     *
     * Frueher stand hier nur die kurze Form "TThhmm". Die Spalte ist als
     * Art "zeit" sortierbar, und ohne Monat und Jahr ist "030215" kein
     * Zeitpunkt: Das Bauteil deutete es als Uhrzeit des heutigen Tages
     * und ordnete die Nachweisung falsch. Die Anzeige bleibt kurz -- so
     * steht es auf dem Vordruck --, der Wert traegt den Monat.
     */
    $abfasszeitRoh = (string) ($zeile['12_abfzeit'] ?? '');
    $abfasszeit = '';
    $abfasszeitKurz = '';
    if ($abfasszeitRoh !== '') {
        $teile = estab_datetime_parts($abfasszeitRoh);
        $abfasszeitKurz = (string) ($teile['stak'] ?? '');
        $abfasszeit = estab_datetime_to_tactical(
            $abfasszeitRoh,
            estab_nv_month_abbreviations()
        );
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
        'abfasszeit_kurz' => $abfasszeitKurz,
        'mittel' => estab_nachweisung_weg($zeile),
        'wunsch' => estab_nachweisung_weg_ist_wunsch($zeile),
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
        . 'm.`12_anhang`,m.`13_abseinheit`,m.`x01_abschluss`,'
        // Das Mittel des tatsaechlich gewaehlten Befoerderungsweges. Ohne
        // diese Spalte zeigte der Nachweis fuer jeden Ausgang das
        // Wunschmittel des Verfassers und behauptete damit einen Weg, den
        // es vielleicht nie gab.
        . 'm.`06_befwegausw`,'
        // Fuer die Beschriftung des TBB-Nachweises: Eine Gespraechsnotiz
        // bekommt nie eine Nummer, ein Ausgang bekommt sie mit der
        // Befoerderung. Ohne diese Spalte koennte die Liste den Grund nicht
        // nennen.
        . 'm.`11_gesprnotiz`'
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
        'beschriftung' => $titel,
        'spalten' => estab_nachweisung_spalten($mitRichtung),
        'zeilen' => estab_nachweisung_zeilen($databaseConfig, $incidentId, $richtung),
        'leer' => 'Kein Nachweis entspricht den gesetzten Filtern.',
    ]);
}
