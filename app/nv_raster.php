<?php

declare(strict_types=1);

/**
 * Das Raster des Nachrichtenvordrucks in Millimetern.
 *
 * Der Vordruck wird an drei Stellen ausgegeben: auf dem Bildschirm, im
 * Browserdruck und als PDF -- einzeln aus der Vordruckliste und gesammelt im
 * Einsatzdossier. `UX-PAPIERBILD` verlangt, dass alle drei dasselbe Blatt
 * zeigen. Bis hierher taten sie das nicht: die Oberflaeche trug das amtliche
 * Raster mit den zwanzig Feldnummern, das PDF noch das Raster des
 * Altbestandes. Wer eine Meldung am Bildschirm sichtete und dann ausdruckte,
 * bekam zwei verschiedene Vordrucke.
 *
 * Die Zahlen hier sind keine Schaetzung. Sie sind am gedruckten Blatt
 * gemessen: die Oberflaeche stellt den Vordruck im Druckmedium mit
 * `zoom: 0.78` auf 698,9 x 1053,5 CSS-Pixel, das sind bei 96 dpi
 * 184,9 x 278,7 mm. Jede Marke unten ist der so gemessene Wert.
 *
 * Der Ursprung ist die linke obere Ecke des Blattes, nicht der Seitenrand.
 * Wer das Blatt auf die Seite setzt, addiert den Versatz einmal.
 */

/** Waagerechte Marken des Blattes, von links. */
function estab_nv_raster_senkrecht(): array
{
    return [
        // Die linke Randspalte traegt die Zonentitel, die Blattfarben und
        // die beiden Lochmarken. Sie gehoert nicht zum Kastenraster.
        'randspalte' => 17.3,
        'raster' => 17.6,
        // Feld 5 steht als eigene Spalte neben den Bearbeitungsvermerken.
        'ttb' => 151.2,
        // Eingang/Ausgang teilen sich dort, wo auch Aufnahme- und
        // Annahmevermerk sich teilen.
        'eingang_ende' => 60.9,
        'annahme_ende' => 104.7,
        // Die Beschriftungsspalte des Mittelteils: Anschrift, Ruf Nr.,
        // Inhalt, Absender, Abfassungszeit.
        'kopfspalte' => 50.7,
        // Feld 12 steht rechts neben Anschrift und Rufnummer.
        'notiz' => 151.4,
        // Feld 16 endet vor dem Absenderzeichen.
        'abfassung_ende' => 119.0,
        // Feld 17: Einheit | Zeichen | Funktion.
        'zeichen' => 119.5,
        'funktion' => 152.9,
        // Der Sichter teilt sich in Quittung/Verteiler links und
        // Vermerke rechts.
        'sichter_teiler' => 119.5,
        'blatt' => 184.9,
    ];
}

/** Senkrechte Marken des Blattes, von oben. */
function estab_nv_raster_waagerecht(): array
{
    return [
        'fmz_oben' => 0.0,
        'medium_ende' => 7.4,     // Feld 1
        'richtung_ende' => 12.8,  // Eingang/Ausgang
        'stempel_kopf' => 18.4,   // Kopfzeile der Vermerke
        'stempel_fuss' => 28.7,   // Datum/Uhrzeit/Hdz.
        'stempel_ende' => 33.4,   // Felder 2 bis 4, und Ende von Feld 5
        'fmz_ende' => 46.4,       // Ende Feld 6
        'inhalt_oben' => 47.3,
        'weg_ende' => 55.7,       // Feld 7
        'art_ende' => 64.1,       // Felder 8 und 9
        'anschrift_ende' => 73.0, // Feld 10
        'adresse_ende' => 87.1,   // Felder 11 und 12
        'betreff_ende' => 97.0,   // Feld 13
        'text_ende' => 195.1,     // Feld 14
        'absender_ende' => 205.7, // Feld 15
        'abfassung_ende' => 216.2, // Feld 16
        'inhalt_ende' => 227.8,   // Ende Feld 17
        'sichter_oben' => 228.7,
        'quittung_ende' => 242.8, // Feld 18
        'sichter_ende' => 278.7,  // Felder 19 und 20
    ];
}

/**
 * Die drei Bearbeitungsvermerke mit ihren Spalten.
 *
 * Jeder Vermerk fuehrt Datum, Uhrzeit und Handzeichen. Die Spaltenbreiten
 * sind nicht gleich -- das Handzeichen braucht weniger Platz als das Datum --
 * und stehen deshalb einzeln.
 *
 * @return list<array{nummer:int,titel:string,links:float,datum:float,zeit:float,zeichen:float,rechts:float}>
 */
function estab_nv_raster_vermerke(): array
{
    return [
        ['nummer' => 2, 'titel' => 'Aufnahmevermerk',
            'links' => 17.6, 'datum' => 33.9, 'zeit' => 50.2,
            'zeichen' => 50.2, 'rechts' => 60.9],
        ['nummer' => 3, 'titel' => 'Annahmevermerk',
            'links' => 60.9, 'datum' => 77.5, 'zeit' => 94.0,
            'zeichen' => 94.0, 'rechts' => 104.7],
        ['nummer' => 4, 'titel' => 'Beförderungsvermerk',
            'links' => 104.7, 'datum' => 122.3, 'zeit' => 139.7,
            'zeichen' => 139.7, 'rechts' => 151.2],
    ];
}

/**
 * Die fuenf Uebermittlungsmittel des Vordrucks in ihrer gedruckten Folge.
 *
 * Der Katalog des Meldewesens kennt mehr (`TKM-KATALOG`), der Vordruck
 * kreuzt nur diese fuenf an. Was nicht darunter faellt, gehoert in Feld 6.
 *
 * `werte` sind die gespeicherten Schluessel. FAX steht gross in der Tabelle,
 * und der heutige Wert `@` teilt sich das Kaestchen DFÜ mit dem historischen
 * Fernschreiben `FS`.
 *
 * @return list<array{name:string,werte:list<string>}>
 */
function estab_nv_raster_mittel(): array
{
    return [
        ['name' => 'Funk', 'werte' => ['Fu']],
        ['name' => 'Telefon', 'werte' => ['Fe']],
        ['name' => 'Telefax', 'werte' => ['FAX']],
        ['name' => 'DFÜ', 'werte' => ['FS', '@']],
        ['name' => 'Kurier/Melder', 'werte' => ['Me']],
    ];
}

/**
 * Die Ankreuzspalten der beiden Mittelzeilen.
 *
 * Feld 1 steht neben der Spalte des Technischen Betriebsbuches und ist
 * deshalb schmaler als Feld 7, das die ganze Blattbreite hat.
 *
 * @return array{feld1:list<float>,feld7:list<float>}
 */
function estab_nv_raster_mittelspalten(): array
{
    return [
        'feld1' => [19.4, 44.5, 69.7, 94.8, 119.9],
        'feld7' => [19.4, 51.3, 83.1, 115.0, 146.8],
    ];
}

/**
 * Die Zeilenhoehe im Nachrichtentext.
 *
 * Das Blatt ist dort liniert: 2,475rem im Druckmedium, mal 0,78 Massstab
 * und 96 dpi ergibt 8,17 mm. Die Linien traegt das Papier, nicht der Text --
 * sie stehen auch dann, wenn das Feld leer bleibt.
 */
function estab_nv_raster_zeilenhoehe(): float
{
    return 8.17;
}

/**
 * Die vier Durchschriften am linken Rand, hochkant von unten nach oben.
 *
 * `unten` ist die Grundlinie am unteren Ende der gedrehten Zeile.
 */
function estab_nv_raster_durchschriften(): array
{
    return [
        ['x' => 3.3, 'unten' => 158.1,
            'text' => 'Blatt 1 (blau) Sachgebiet/Fachber./Verbindungsst.'],
        ['x' => 7.4, 'unten' => 158.3,
            'text' => 'Blatt 2 (grün) Sachgebiet/Fachber./Verbindungsst.'],
        ['x' => 11.5, 'unten' => 151.3,
            'text' => 'Blatt 3 (rot) Sachgebiet 2 Lage'],
        ['x' => 15.6, 'unten' => 152.2,
            'text' => 'Blatt 4 (gelb) Techn. Betriebsbuch'],
    ];
}

/**
 * Die beiden Lochmarken am linken Rand.
 *
 * Auf Papier sind es Loecher; auf dem Blatt stehen sie als Marke, damit der
 * Ausdruck an derselben Stelle gelocht wird.
 */
function estab_nv_raster_lochmarken(): array
{
    return ['x' => 13.0, 'radius' => 3.3, 'y' => [81.6, 195.3]];
}

/**
 * Das ganze Raster in einem Aufruf.
 *
 * @return array<string,mixed>
 */
function estab_nv_raster(): array
{
    return [
        'breite' => 184.9,
        'hoehe' => 278.7,
        'x' => estab_nv_raster_senkrecht(),
        'y' => estab_nv_raster_waagerecht(),
        'vermerke' => estab_nv_raster_vermerke(),
        'mittel' => estab_nv_raster_mittel(),
        'mittelspalten' => estab_nv_raster_mittelspalten(),
        // Kantenlaengen der Ankreuzfelder, wie am Blatt gemessen.
        'kaestchen' => [
            'mittel' => 4.8,
            'art' => 4.8,
            'vorrang' => 3.6,
            'notiz' => 7.8,
            'ttb' => 4.8,
            'verteiler' => 3.6,
        ],
        'zeilenhoehe' => estab_nv_raster_zeilenhoehe(),
        'durchschriften' => estab_nv_raster_durchschriften(),
        'lochmarken' => estab_nv_raster_lochmarken(),
        // Die Papierfarbe von Blatt 1. Oberflaeche und Browserdruck zeigen
        // denselben Ton; ein weisses PDF waere wieder ein anderes Blatt.
        'papier' => ['r' => 162, 'g' => 217, 'b' => 247],
        // Strichstaerken. Der Balken zwischen den Zonen ist der einzige,
        // den man auf Armlaenge noch sieht; er trennt Fernmeldezentrale,
        // Verfasser und Sichter.
        'strich' => [
            'zelle' => 0.34,
            'rahmen' => 0.34,
            'balken' => 0.8,
        ],
        // Schriftgroessen in Millimetern auf dem gedruckten Blatt. Die
        // Umrechnung in Punkt macht der Zeichner.
        //
        // Achtung beim Nachmessen: die Oberflaeche stellt den Vordruck im
        // Druckmedium mit `zoom: 0.78`. Die Kastenmasse liefert der Browser
        // fertig skaliert, die Schriftgroesse aber unskaliert -- wer sie
        // ungerechnet uebernimmt, setzt jede Beschriftung um ein Viertel zu
        // gross, und die Felder laufen ineinander. Die Werte hier sind
        // bereits mit 0,78 gerechnet.
        'schrift' => [
            'zonentitel' => 5.12,
            'feld' => 3.30,
            'wert' => 3.30,
            'klein' => 2.89,
            'mittel' => 3.04,
            'verteiler' => 2.50,
            'gruppe' => 2.65,
            'nummer' => 1.72,
            'durchschrift' => 1.42,
            'hinweis' => 2.50,
        ],
    ];
}
