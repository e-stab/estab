<?php

declare(strict_types=1);

/**
 * Was ein Knopf sendet, muss jemand lesen.
 *
 * Die Bedienelemente der Meldungsliste waren einmal Bilder --
 * `<input type="image">`. Ein Bildknopf sendet nicht seinen Namen, sondern
 * die Klickkoordinaten darauf: `flt_for_x` und `flt_for_y`. Die Anwendung
 * fragt deshalb überall nach dem `_x`.
 *
 * Als die Bilder durch echte Knöpfe ersetzt wurden (77c731a), fiel das `_x`
 * weg. Die Knöpfe heissen seither `flt_for`, gelesen wird weiterhin
 * `flt_for_x` -- und der Wert steht nicht einmal in der Liste der erlaubten
 * Handlungen. Blättern, Erledigt-Filter, Unerledigt-Filter und die
 * Suchmaske taten seitdem nichts.
 *
 * > „Die Paginierung beim lesen als Stab funktioniert nicht."
 *
 * Der Fehler ist stumm: Der Knopf sieht aus wie ein Knopf, die Seite lädt neu,
 * und es ändert sich nichts. Wer ihn drückt, hält die Liste für vollständig.
 *
 * Geprüft wird deshalb der Schluss selbst: Jeder Name, den ein Knopf der
 * Liste sendet, muss in der Auswertung vorkommen **und** unter den erlaubten
 * Handlungen stehen. Ein Knopf, den niemand liest, ist kein Knopf.
 */

$root = dirname(__DIR__, 2);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$liste = file_get_contents($root . '/4fach/liste.php');
$dispatch = file_get_contents($root . '/4fach/mainindex.php');
$erlaubt = file_get_contents($root . '/app/workflow.php');
$assert(
    is_string($liste) && is_string($dispatch) && is_string($erlaubt),
    'Liste, Auswertung oder Handlungsliste sind nicht lesbar.'
);
$liste = (string) $liste;
$dispatch = (string) $dispatch;
$erlaubt = (string) $erlaubt;

/**
 * Die Namen, die die Knöpfe der Liste senden.
 *
 * Gesucht wird an den Aufrufstellen der beiden Bauhilfen, nicht im ganzen
 * Text: Ein Name, der irgendwo als Zeichenkette steht, ist noch kein Knopf.
 *
 * @return list<string>
 */
$knopfnamen = static function (string $quelle, string $funktion): array {
    $namen = [];
    $stelle = 0;
    while (($treffer = strpos($quelle, $funktion . ' (', $stelle)) !== false) {
        $stelle = $treffer + strlen($funktion);
        $abschnitt = substr($quelle, $treffer, 260);
        if (preg_match_all('~"([a-z0-9_]+)"~', $abschnitt, $gefunden) === false) {
            continue;
        }
        foreach ($gefunden[1] as $wert) {
            // Der erste Zeichenkettenwert nach dem Aufruf ist der Name;
            // die Bedingung davor kann zwei anbieten.
            if (preg_match('~\A(?:flt_|filter_)[a-z_]+\z~', $wert) === 1) {
                $namen[] = $wert;
            }
        }
    }
    return array_values(array_unique($namen));
};

$gesendet = array_merge(
    $knopfnamen($liste, 'estab_list_pager_markup'),
    $knopfnamen($liste, 'estab_list_toggle_markup')
);
$assert(
    count($gesendet) >= 8,
    'Es werden nur ' . count($gesendet) . ' Knopfnamen gefunden. Die Suche '
        . 'greift nicht mehr, und ihre Ruhe waere kein Beweis.'
);

/*
 * Die Kette hat zwei Glieder, und beide werden geprüft.
 *
 * Erstens: Beide Bauhilfen schreiben den Namen nicht selbst, sondern lassen
 * ihn durch estab_list_handlungsname laufen. Zweitens: Diese Hilfe hängt das
 * Suffix an. Ohne das erste Glied bekäme ein einzelner Knopf wieder einen
 * eigenen Namen, ohne das zweite alle zusammen den falschen.
 */
foreach (['estab_list_toggle_markup', 'estab_list_pager_markup'] as $hilfe) {
    if (preg_match(
        '~function ' . $hilfe . ' \([^)]*\) \{(.*?)\n\}~s',
        $liste,
        $rumpf
    ) !== 1) {
        throw new RuntimeException('Die Bauhilfe ' . $hilfe . ' fehlt.');
    }
    $assert(
        str_contains($rumpf[1], 'name=\\"".estab_list_handlungsname ('),
        'Die Bauhilfe ' . $hilfe . ' setzt den Namen ihres Knopfes selbst. '
            . 'Damit kann sie ihn wieder anders setzen als die Auswertung ihn '
            . 'liest.'
    );
}
if (preg_match(
    '~function estab_list_handlungsname \([^)]*\) \{(.*?)\n\}~s',
    $liste,
    $rumpf
) !== 1) {
    throw new RuntimeException('estab_list_handlungsname fehlt.');
}
/*
 * Und der zweite Fehler, der unter dem ersten lag: Die Blätterknöpfe stehen
 * gar nicht in der Filterleiste. `listen_navi()` gibt sie nach
 * `darstellungs_art()` aus, also ausserhalb des Formulars -- und ein
 * `type="submit"` ohne Formular tut nichts.
 *
 * Der Name allein hätte das nicht geheilt: Der Knopf hätte weiterhin
 * geschwiegen. Das form-Attribut verbindet ihn mit der Leiste, in der er
 * nicht steht.
 */
if (preg_match(
    '~function estab_list_pager_markup \([^)]*\) \{(.*?)\n\}~s',
    $liste,
    $blaetterer
) !== 1) {
    throw new RuntimeException('estab_list_pager_markup fehlt.');
}
$assert(
    str_contains($blaetterer[1], 'form=\\"estab-list-filter\\"'),
    'Die Blätterknöpfe sind nicht mit der Filterleiste verbunden. Sie stehen '
        . 'ausserhalb ihres Formulars, und ein Absendeknopf ohne Formular tut '
        . 'nichts.'
);
$assert(
    substr_count($liste, 'id=\\"estab-list-filter\\"') === 2,
    'Die Filterleiste traegt die Kennung nicht, auf die die Blätterknöpfe '
        . 'zeigen -- oder nicht in beiden Listenarten. Ein form-Attribut ins '
        . 'Leere ist so still wie gar keines.'
);

$assert(
    str_contains($rumpf[1], '"_x"'),
    'estab_list_handlungsname haengt das Suffix nicht mehr an. Die '
        . 'Auswertung und die Liste der erlaubten Handlungen fragen im '
        . 'ganzen Bestand nach dem _x.'
);

$stumm = [];
$ungedeckt = [];
foreach ($gesendet as $name) {
    $handlung = $name . '_x';
    if (!str_contains($dispatch, '"' . $handlung . '"')
        && !str_contains($dispatch, "'" . $handlung . "'")) {
        $stumm[] = $handlung;
    }
    if (!str_contains($erlaubt, "'" . $handlung . "'")) {
        $ungedeckt[] = $handlung;
    }
}
$assert(
    $stumm === [],
    'Diese Knoepfe der Meldungsliste senden einen Namen, den die Auswertung '
        . 'nicht kennt -- sie tun nichts, und der Fehler ist stumm: '
        . implode(', ', $stumm)
);
$assert(
    $ungedeckt === [],
    'Diese Knopfnamen stehen nicht unter den erlaubten Handlungen und werden '
        . 'verworfen, bevor sie jemand liest: ' . implode(', ', $ungedeckt)
);

printf(
    "Blaettern und Filterleiste: OK (%d assertions, %d Knopfnamen geprueft)\n",
    $assertions,
    count($gesendet)
);
