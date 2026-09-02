<?php

declare(strict_types=1);

/**
 * Die Namen, unter denen ein Tabellensieb in der Adresse steht.
 *
 * Diese Namen sind geteiltes Wissen: Das Tabellenbauteil erzeugt sie, und
 * Stellen, die ihre Anfragen eng fuehren, muessen sie wiedererkennen. Die
 * Anmeldeseite tut das -- sie nimmt vor der Anmeldung nur eine genau
 * umrissene Anfrage an und braucht dafuer die Namen, nicht das Bauteil.
 *
 * Deshalb stehen sie in einer eigenen Datei ohne jede Abhaengigkeit. Das
 * Bauteil selbst zieht die Meldungsschicht nach; ein Sicherheitswaechter,
 * der nur wissen will, wie ein Suchfeld heisst, soll das nicht muessen.
 */

/**
 * Die Namen der Adressfelder einer Tabelle.
 *
 * Jede Tabelle trägt ihre Kennung im Feldnamen. Zwei Tabellen auf einer Seite
 * blättern sonst gemeinsam, und das fällt erst auf, wenn es beide gibt.
 *
 * `$eigene` überschreibt einzelne Namen. Das braucht eine Seite, die ihre
 * Auswahl selbst trifft -- die Meldungsübersicht siebt in der Datenbank und
 * kennt ihre Adressfelder seit jeher unter `ml_`. Sie soll deswegen nicht
 * ihre Adressen ändern müssen; das Bauteil lernt stattdessen ihre Namen.
 *
 * @param array<string,string> $eigene
 * @return array<string,string>
 */
function estab_tabelle_felder(string $id, array $eigene = []): array
{
    $marke = preg_replace('~[^a-z0-9]+~', '_', mb_strtolower($id)) ?? 't';
    return array_merge([
        'sortierung' => $marke . '_sort',
        'richtung' => $marke . '_richtung',
        'seite' => $marke . '_seite',
        'groesse' => $marke . '_groesse',
        'suche' => $marke . '_suche',
        'spalte' => $marke . '_s_',
        'filter' => $marke . '_f_',
    ], array_filter(
        $eigene,
        static fn (mixed $wert): bool => is_string($wert) && $wert !== ''
    ));
}

/**
 * Gehoert ein Anfrageschluessel zum Sieb dieser Tabelle?
 *
 * Das Sieb steht in der Adresse, damit eine Suche den Seitenaufbau
 * ueberlebt und sich weitergeben laesst. Eine Stelle, die ihre Anfragen
 * eng fuehrt -- die Anmeldeseite tut das --, muss deshalb sagen koennen,
 * welche Schluessel zu ihrer Tabelle gehoeren. Sie fragt hier, statt die
 * Namen selbst nachzubauen: Ein zweiter Ort mit denselben Namen waere ein
 * zweiter Ort, der bei jeder Aenderung mitgezogen werden muss.
 *
 * Der Wert muss eine Zeichenkette sein. Das Bauteil selbst verwirft
 * anderes ohnehin; hier faellt es schon an der Tuer auf.
 */
function estab_tabelle_ist_siebschluessel(string $id, string $schluessel): bool
{
    $felder = estab_tabelle_felder($id);
    foreach (['sortierung', 'richtung', 'seite', 'groesse', 'suche'] as $name) {
        if ($schluessel === $felder[$name]) {
            return true;
        }
    }
    // Die Spalten- und Filternamen sind Vorsilben. Die Vorsilbe allein ist
    // kein Schluessel -- `konten_s_` benennt keine Spalte.
    foreach (['spalte', 'filter'] as $name) {
        if (
            $schluessel !== $felder[$name]
            && str_starts_with($schluessel, $felder[$name])
        ) {
            return true;
        }
    }
    return false;
}
