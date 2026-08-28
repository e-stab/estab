<?php

declare(strict_types=1);

/**
 * The one place where the Nachrichtenvordruck's field numbers are translated.
 *
 * The form is counted twice. The Ausfüllanleitung numbers twenty fields and
 * prints those numbers into the corners of the boxes, so a responder who
 * reads "13" looks up instruction 13. The Stab-Unterlage counts seventeen,
 * because it draws several boxes as one and omits the ones the staff never
 * fills. The application carries a third scale on top: a per-field access
 * index that decides which boxes a workflow step opens.
 *
 * As long as every site translated for itself, each site was right and the
 * whole was unreadable: a comment says "Feld 19", the line below reaches for
 * index 16, and whoever does not know the tables by heart reads that as a
 * defect -- or misses a real one. This module is the single translation, and
 * the form view speaks only the scale it prints.
 *
 * The access index is not the Unterlage count. It keeps a retired transport
 * hint on slot 8, and several boxes share one index because the workflow step
 * releases them together. Both facts are historic and are recorded here
 * rather than rediscovered at each call site.
 *
 * The Abfassungszeit held index 12 together with Betreff and Nachrichtentext.
 * That made it impossible to close on its own -- and closing it with them
 * would have closed exactly what the Fernmelder writes down when a message
 * comes in. It therefore holds index 18, one past the printed scale, which is
 * why the access array runs to 18 while the Unterlage stops at 17.
 */

/**
 * The normative table: printed number => Unterlage number, access index, name.
 *
 * @return array<int, array{unterlage:?int, zugriff:int, bezeichnung:string}>
 */
function estab_nv_field_map(): array
{
    return [
        1 => ['unterlage' => null, 'zugriff' => 1,
            'bezeichnung' => 'Übermittlungsmittel, tatsächlich benutzt'],
        2 => ['unterlage' => 1, 'zugriff' => 1,
            'bezeichnung' => 'Aufnahmevermerk'],
        3 => ['unterlage' => 2, 'zugriff' => 2,
            'bezeichnung' => 'Annahmevermerk'],
        4 => ['unterlage' => 3, 'zugriff' => 3,
            'bezeichnung' => 'Beförderungsvermerk'],
        5 => ['unterlage' => 4, 'zugriff' => 4,
            'bezeichnung' => 'Technisches Betriebsbuch, Richtung und Nummer'],
        6 => ['unterlage' => 5, 'zugriff' => 5,
            'bezeichnung' => 'Rufname der Gegenstelle'],
        7 => ['unterlage' => 6, 'zugriff' => 6,
            'bezeichnung' => 'Übermittlungsmittel, gewünscht'],
        8 => ['unterlage' => 7, 'zugriff' => 7,
            'bezeichnung' => 'Durchsage oder Spruch'],
        9 => ['unterlage' => 8, 'zugriff' => 9,
            'bezeichnung' => 'Vorrangstufe'],
        10 => ['unterlage' => 9, 'zugriff' => 10,
            'bezeichnung' => 'Anschrift'],
        11 => ['unterlage' => null, 'zugriff' => 10,
            'bezeichnung' => 'Rufnummer der Gegenstelle'],
        12 => ['unterlage' => 10, 'zugriff' => 11,
            'bezeichnung' => 'Gesprächsnotiz'],
        13 => ['unterlage' => 11, 'zugriff' => 12,
            'bezeichnung' => 'Inhalt, Betreff'],
        14 => ['unterlage' => null, 'zugriff' => 12,
            'bezeichnung' => 'Nachricht, Text'],
        15 => ['unterlage' => 12, 'zugriff' => 13,
            'bezeichnung' => 'Absender'],
        16 => ['unterlage' => 12, 'zugriff' => 18,
            'bezeichnung' => 'Abfassungszeit'],
        17 => ['unterlage' => 14, 'zugriff' => 14,
            'bezeichnung' => 'Zeichen und Funktion des Verfassers'],
        18 => ['unterlage' => 15, 'zugriff' => 15,
            'bezeichnung' => 'Quittung'],
        19 => ['unterlage' => 16, 'zugriff' => 16,
            'bezeichnung' => 'Verteiler'],
        20 => ['unterlage' => 17, 'zugriff' => 17,
            'bezeichnung' => 'Vermerke und Erledigung'],
    ];
}

/**
 * Resolve one printed field number, failing loudly on a number the form
 * does not have.
 *
 * @return array{unterlage:?int, zugriff:int, bezeichnung:string}
 */
function estab_nv_field(int $number): array
{
    $entry = estab_nv_field_map()[$number] ?? null;
    if ($entry === null) {
        throw new InvalidArgumentException(
            'Der Nachrichtenvordruck hat kein Feld ' . $number
        );
    }
    return $entry;
}

/** Which access index carries this printed field? */
function estab_nv_access_index(int $number): int
{
    return estab_nv_field($number)['zugriff'];
}

/** What does the Stab-Unterlage call this printed field, if anything? */
function estab_nv_unterlage_number(int $number): ?int
{
    return estab_nv_field($number)['unterlage'];
}
