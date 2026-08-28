<?php

declare(strict_types=1);

/**
 * Die eine Ausnahme: das Papierfaksimile.
 *
 * Diese Datei hiess einmal `migrationsgrenze.php` und fuehrte 49
 * Auswaehlerpraefixe -- den gesamten Bestand, der noch nicht auf die Marken
 * umgestellt war. Die Waechter waren fuer alles scharf, was nicht darin
 * stand, und jede Aufgabe des Umsetzungsplans strich ihre Praefixe. Am Ende
 * blieb einer uebrig, und der bleibt.
 *
 * Der Nachrichtenvordruck ist keine unfertige Umstellung. Er ist ein
 * Papierfaksimile mit eigenem Raster, und die Dienstvorschrift gibt dieses
 * Raster vor. Schrift-, Abstands- und Radienskala gelten darin nicht: Das
 * Raster ist Geometrie, keine Skala -- wer dort ein Mass zieht, verschiebt
 * ein Feld. Der Lochabstand von 3.4rem und der Spaltenversatz von 5.25rem
 * sind Masse des Blattes, keine Stufen.
 *
 * Was sehr wohl gilt, und zwar ohne Abzug: Kontrast (ux_form_contrast, 14
 * Paarungen gegen 7:1), Fokus (ges_fokus), Massstab und Lesbarkeit
 * (ges_vordruck), Zustaende ohne Farbe.
 *
 * Siehe docs/GESTALTUNG.md Abschnitt 12 und Abschnitt 1.4.
 */

/** Steht dieser Auswähler im Papierfaksimile? */
function estab_test_ist_vordruck(string $auswaehler): bool
{
    return str_contains($auswaehler, 'estab-official');
}

/**
 * Frueherer Name derselben Frage.
 *
 * Die Waechter fragten „steht das noch in der Migrationsgrenze?". Die Frage
 * heisst jetzt anders, aber sie ist dieselbe -- und der alte Name bleibt
 * eine Weile stehen, damit ein Test, der ihn noch benutzt, nicht still
 * durchrutscht, sondern weiter dasselbe prueft.
 */
function estab_test_in_migrationsgrenze(string $auswaehler): bool
{
    return estab_test_ist_vordruck($auswaehler);
}
