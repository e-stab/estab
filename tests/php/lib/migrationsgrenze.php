<?php

declare(strict_types=1);

/**
 * Die Migrationsgrenze: wo im Stylesheet noch Zahlen stehen dürfen.
 *
 * Eine Prüfung, die „kein Farbliteral außerhalb von :root" verlangt, kann erst
 * grün werden, wenn der letzte Bereich umgestellt ist. Bis dahin prüfte
 * nichts -- und genau in dieser Zeit kämen neue Abweichungen dazu.
 *
 * Deshalb ist es umgekehrt: Die Wächter sind von Anfang an scharf, und diese
 * Liste sagt, was sie noch durchgehen lassen. Jede Aufgabe des Umsetzungsplans
 * streicht ihre Präfixe; die letzte entfernt diese Datei.
 *
 * Ein Bereich, der künftig dazukommt, steht nicht in der Liste und wird damit
 * sofort geprüft. Das ist der eigentliche Zweck: Nicht am Ende feststellen,
 * dass alles passt, sondern von Tag eins verhindern, dass es auseinanderläuft.
 *
 * Siehe tasks/gestaltung-plan.md Abschnitt 2.3.
 */

/**
 * Auswählerpräfixe, die noch nicht auf die Marken umgestellt sind.
 *
 * Beim Anlegen deckt die Liste den gesamten Bestand ab. Sie ist die
 * Ausgangslage, nicht das Ziel.
 *
 * @return list<string>
 */
function estab_test_migrationsgrenze(): array
{
    return [
        /*
         * Der Nachrichtenvordruck steht hier als Einziger -- und er bleibt.
         * Er ist keine unfertige Umstellung, sondern die begruendete Ausnahme
         * aus docs/GESTALTUNG.md Abschnitt 12: ein Papierfaksimile mit
         * eigenem Raster. Schrift-, Radien- und Abstandsskala gelten darin
         * nicht; sein Raster ist Geometrie, keine Skala. Wer dort ein Mass
         * zieht, verschiebt ein Feld.
         *
         * Kontrast, Fokus und die Zustaende ohne Farbe gelten sehr wohl --
         * die pruefen ux_form_contrast, ges_fokus und ges_vordruck.
         */
        'estab-official',
    ];
}

/**
 * Trägt dieser Auswähler noch die Ausnahme?
 *
 * Geprüft wird auf Präfix, nicht auf Gleichheit: `.estab-tool-` deckt
 * `.estab-tool-panel` und `.estab-tool-field` mit ab, ohne dass jede
 * Ausprägung einzeln aufgezählt werden muss.
 */
function estab_test_in_migrationsgrenze(string $auswaehler): bool
{
    foreach (estab_test_migrationsgrenze() as $praefix) {
        if (str_contains($auswaehler, $praefix)) {
            return true;
        }
    }
    return false;
}
