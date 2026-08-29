<?php

declare(strict_types=1);

/**
 * Die Spalten der Fernmelde- und Führungslisten.
 *
 * Ausgang und Disposition schrieben ihr Tabellenmarkup selbst -- jede mit
 * eigenen Überschriften, eigener Breite und ohne Sortierung, Suche oder
 * Blätterer. Das ist die Uneinheitlichkeit, die im Betrieb auffällt: Wer
 * sich in einer Liste zurechtgefunden hat, findet sich in der nächsten
 * nicht wieder.
 *
 * Die Beschreibungen stehen hier und nicht in der Seite, damit sie sich
 * prüfen lassen, ohne eine Datenbank, eine Anmeldung und einen Einsatz
 * aufzubauen. Die Seite liefert die Zeilen; wie die Tabelle aussieht und
 * was sie kann, steht an einer Stelle.
 */

require_once __DIR__ . '/dv_operations.php';

/**
 * Das Übermittlungsmittel beim Namen nennen.
 *
 * Im Vordruck steht das Kürzel -- „Fu", „Fe", „@". In einer Liste, die ein
 * Fernmelder im Dienst überfliegt, sagt das Kürzel weniger als das Wort,
 * und in einem Filterfeld gar nichts.
 *
 * Ein leeres Feld bleibt leer. `estab_dv_telecom_medium_label` antwortet
 * darauf mit „Unbekannt ()"; das wäre ein erfundenes Wort, das in der
 * Sortierung mitten in der Liste stünde, statt sich der Leerregel zu
 * fügen und ans Ende zu gehen.
 */
function estab_liste_medium_name(mixed $medium): string
{
    if (!is_string($medium) || trim($medium) === '') {
        return '';
    }
    return estab_dv_telecom_medium_label(trim($medium));
}

/**
 * Die Spalten der Ausgangsliste des Fernmelders.
 *
 * Das Übermittlungsmittel ist hier keine Zusatzangabe, sondern die
 * Arbeitsteilung: Eine Fernmeldestelle verteilt ihre Plätze nach Mitteln,
 * und wer den Funk betreut, will die Funkaufträge sehen. Deshalb steht das
 * Mittel als eigene Spalte, sortierbar und mit einem Filter, der nur die
 * Mittel anbietet, die im laufenden Einsatz auch vorkommen.
 *
 * @param list<string> $medien Die vorkommenden Mittel, bereits benannt.
 * @return list<array<string,mixed>>
 */
function estab_liste_spalten_ausgang(array $medien): array
{
    return [
        [
            'schluessel' => 'zeit', 'kopf' => 'Zeit', 'breite' => 12,
            'sortierbar' => true, 'suchbar' => true, 'art' => 'zeit',
        ],
        [
            'schluessel' => 'medium', 'kopf' => 'Mittel', 'breite' => 15,
            'sortierbar' => true, 'suchbar' => true, 'art' => 'text',
            'filter' => array_values($medien), 'filtername' => 'Alle Mittel',
        ],
        [
            'schluessel' => 'vorrang', 'kopf' => 'Vorrang', 'breite' => 11,
            'sortierbar' => true, 'suchbar' => true, 'art' => 'vorrang',
        ],
        [
            'schluessel' => 'anschrift', 'kopf' => 'Anschrift', 'breite' => 19,
            'sortierbar' => true, 'suchbar' => true, 'art' => 'text',
        ],
        [
            'schluessel' => 'inhalt', 'kopf' => 'Inhalt', 'breite' => 31,
            'sortierbar' => false, 'suchbar' => true, 'art' => 'text',
            // Kein `klammern`: Die Seite setzt fuer diese Spalte eine
            // eigene Zelle, die den Text selbst kuerzt und die Anlagenmarke
            // *dahinter* haengt. Wuerde das Bauteil zusaetzlich klammern,
            // stuende die Marke innerhalb der Kuerzung -- und die schneidet
            // nach zwei Zeilen ab.
        ],
    ];
}

/**
 * Die Spalten der Dispositionsliste der Leitung des Fernmeldebetriebs.
 *
 * Die Liste führt Eingänge und Ausgänge nebeneinander -- beim Disponieren
 * ist beides zu sehen. Getrennt werden können muss es trotzdem, deshalb
 * trägt die Richtungsspalte einen Filter.
 *
 * @return list<array<string,mixed>>
 */
function estab_liste_spalten_disposition(): array
{
    return [
        [
            'schluessel' => 'richtung', 'kopf' => 'E/A', 'breite' => 8,
            'sortierbar' => true, 'suchbar' => true, 'art' => 'text',
            'filter' => ['Eingang', 'Ausgang'],
            'filtername' => 'Ein- und Ausgang',
        ],
        [
            'schluessel' => 'zeit', 'kopf' => 'Zeit', 'breite' => 12,
            'sortierbar' => true, 'suchbar' => true, 'art' => 'zeit',
        ],
        [
            'schluessel' => 'vorrang', 'kopf' => 'Vorrang', 'breite' => 11,
            'sortierbar' => true, 'suchbar' => true, 'art' => 'vorrang',
        ],
        [
            'schluessel' => 'rufname', 'kopf' => 'Rufname', 'breite' => 15,
            'sortierbar' => true, 'suchbar' => true, 'art' => 'text',
        ],
        [
            'schluessel' => 'gegenstelle', 'kopf' => 'Von/An', 'breite' => 17,
            'sortierbar' => true, 'suchbar' => true, 'art' => 'text',
        ],
        [
            'schluessel' => 'inhalt', 'kopf' => 'Inhalt', 'breite' => 25,
            'sortierbar' => false, 'suchbar' => true, 'art' => 'text',
            // Siehe Ausgang: Die Zelle kuerzt selbst.
        ],
    ];
}
