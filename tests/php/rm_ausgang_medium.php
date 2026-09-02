<?php

declare(strict_types=1);

/**
 * Ausgang und Disposition: dieselbe Tabelle wie ueberall -- und ein Filter
 * nach dem Uebermittlungsmittel.
 *
 * Beide Listen schrieben ihr Markup selbst. Die Ausgangsliste des
 * Fernmelders trug vier Spalten ohne Sortierung, ohne Suche, ohne
 * Blaetterer -- und ohne das Mittel, ueber das die Meldung hinausgeht.
 * Genau danach arbeitet aber eine Fernmeldestelle: Wer den Funk betreut,
 * will die Funkauftraege sehen und nicht die Faxe.
 *
 * Die Spaltenbeschreibungen liegen deshalb in einer eigenen, reinen Stelle.
 * Sie lassen sich dort pruefen, ohne eine Datenbank, eine Anmeldung und
 * einen Einsatz aufzubauen -- und die Seite kann nicht danebenliegen, weil
 * sie sie nicht selbst schreibt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once $root . '/app/liste_spalten.php';
require_once $root . '/app/tabelle.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Eine Spalte aus einer Beschreibung heraussuchen. */
$spalte = static function (array $spalten, string $schluessel): ?array {
    foreach ($spalten as $einzeln) {
        if (($einzeln['schluessel'] ?? '') === $schluessel) {
            return $einzeln;
        }
    }
    return null;
};

// ---------------------------------------------------------------- Ausgang

$medien = ['Fernsprecher', 'Funk'];
$ausgang = estab_liste_spalten_ausgang($medien);

$medienspalte = $spalte($ausgang, 'medium');
$assert(
    is_array($medienspalte),
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Die Ausgangsliste hat keine Spalte fuer das Uebermittlungsmittel.'
    )
);
$assert(
    ($medienspalte['sortierbar'] ?? false) === true
        && ($medienspalte['suchbar'] ?? false) === true,
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Die Medienspalte laesst sich nicht sortieren oder nicht durchsuchen.'
    )
);
$assert(
    ($medienspalte['filter'] ?? []) === $medien,
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Die Medienspalte bietet keinen Filter mit den vorkommenden Mitteln.'
    )
);

// Die Vorrangspalte wird nach Dringlichkeit sortiert, nicht alphabetisch.
$vorrang = $spalte($ausgang, 'vorrang');
$assert(
    is_array($vorrang) && ($vorrang['art'] ?? '') === 'vorrang',
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Die Vorrangspalte des Ausgangs wird nicht als Vorrang sortiert; '
            . 'alphabetisch stuende Blitz vor Staatsnot.'
    )
);
$zeit = $spalte($ausgang, 'zeit');
$assert(
    is_array($zeit) && ($zeit['art'] ?? '') === 'zeit',
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Die Zeitspalte des Ausgangs wird nicht nach Zeitpunkt sortiert.'
    )
);

// Und der Filter wirkt wirklich: aus drei Zeilen bleibt die eine mit Funk.
$zeilen = [
    ['zeit' => '01.01.2026 08:00', 'medium' => 'Funk', 'vorrang' => 'Sofort',
        'anschrift' => 'ZUG 1', 'inhalt' => 'Erste Meldung'],
    ['zeit' => '01.01.2026 08:05', 'medium' => 'Fernsprecher', 'vorrang' => 'Einfach',
        'anschrift' => 'ZUG 2', 'inhalt' => 'Zweite Meldung'],
    ['zeit' => '01.01.2026 08:10', 'medium' => 'Fernsprecher', 'vorrang' => 'Einfach',
        'anschrift' => 'ZUG 3', 'inhalt' => 'Dritte Meldung'],
];
$bauen = static function (array $quelle) use ($ausgang, $zeilen): string {
    return estab_tabelle_markup([
        'id' => 'ausgang',
        'beschriftung' => 'Meldungen im Ausgang',
        'spalten' => $ausgang,
        'zeilen' => $zeilen,
        'quelle' => $quelle,
        'leer' => 'Keine Meldung.',
    ]);
};
$ohne = $bauen([]);
$assert(
    substr_count($ohne, '<tr') === 5,
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Die Ausgangstabelle zeigt nicht drei Zeilen (Kopf, Masken, drei '
            . 'Zeilen), sondern ' . substr_count($ohne, '<tr') . ' Zeilen.'
    )
);
$assert(
    str_contains($ohne, '<option value="Funk"')
        && str_contains($ohne, '<option value="Fernsprecher"')
        && str_contains($ohne, 'Alle Mittel'),
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Das Band bietet keine Auswahl der vorkommenden Mittel an.'
    )
);
$nurFunk = $bauen(['ausgang_f_medium' => 'Funk']);
$assert(
    substr_count($nurFunk, '<tr') === 3
        && str_contains($nurFunk, 'Erste Meldung')
        && !str_contains($nurFunk, 'Zweite Meldung'),
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Der Filter nach dem Uebermittlungsmittel waehlt nicht aus; ein '
            . 'Fernmelder saehe weiterhin die Auftraege der anderen Mittel.'
    )
);

// Das Mittel steht beim Namen, nicht als Kuerzel. "Fu" sagt einem
// Fernmelder im Dienst nichts, was "Funk" nicht deutlicher saegte.
$assert(
    estab_liste_medium_name('Fu') === 'Funk'
        && estab_liste_medium_name('Fe') === 'Fernsprecher'
        && estab_liste_medium_name('@') === 'Datenübertragung',
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Das Uebermittlungsmittel wird nicht beim Namen genannt.'
    )
);
// Ein leeres Feld bleibt leer -- und nicht "Unbekannt ()". Die Leerregel
// der Sortierung stellt leere Werte ans Ende; ein erfundenes Wort stuende
// mitten in der Liste.
$assert(
    estab_liste_medium_name('') === '',
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Ein fehlendes Uebermittlungsmittel bekommt einen erfundenen Namen: '
            . estab_liste_medium_name('')
    )
);

// ----------------------------------------------------------- Disposition

$disposition = estab_liste_spalten_disposition();
$richtung = $spalte($disposition, 'richtung');
$assert(
    is_array($richtung)
        && ($richtung['filter'] ?? []) !== []
        && ($richtung['sortierbar'] ?? false) === true,
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Die Dispositionsliste laesst sich nicht nach Eingang und Ausgang '
            . 'trennen.'
    )
);
$dispoVorrang = $spalte($disposition, 'vorrang');
$assert(
    is_array($dispoVorrang) && ($dispoVorrang['art'] ?? '') === 'vorrang',
    estab_ux_requirement(
        'GES-TABELLE-SORTIERUNG',
        'Die Vorrangspalte der Disposition wird nicht als Vorrang sortiert.'
    )
);

// ------------------------------------------------- und die Seite nutzt sie

$quelle = file_get_contents($root . '/4fach/liste.php');
$assert(is_string($quelle), 'liste.php ist nicht lesbar.');
$quelle = (string) $quelle;
$assert(
    str_contains($quelle, 'estab_liste_spalten_ausgang (')
        && str_contains($quelle, 'estab_liste_spalten_disposition ('),
    estab_ux_requirement(
        'GES-TABELLE-EINHEITLICH',
        'Ausgang oder Disposition beschreiben ihre Spalten weiterhin selbst. '
            . 'Eine zweite Beschreibung laeuft von der ersten weg, und dann '
            . 'stimmt die Pruefung oben fuer eine Tabelle, die niemand sieht.'
    )
);

printf(
    "Ausgang und Disposition: OK (%d assertions, %d Spalten Ausgang, "
        . "%d Spalten Disposition)\n",
    $assertions,
    count($ausgang),
    count($disposition)
);
