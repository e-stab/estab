<?php

declare(strict_types=1);

/**
 * Melder und Kurier -- und was ein Melder schuldet.
 *
 * Die Grundlagen des Meldewesens unterscheiden zwei Wege, eine Nachricht
 * durch einen Menschen zu befördern. Der Melder kennt den Inhalt und kann
 * deshalb Rückfragen der Gegenstelle beantworten. Der Kurier kennt ihn
 * nicht; er trägt einen verschlossenen Umschlag. Der Unterschied ist keine
 * Feinheit: Wer einen Kurier losschickt und mit Rückfragen rechnet, wartet
 * auf eine Antwort, die niemand geben kann.
 *
 * Der amtliche Vordruck trifft die Unterscheidung nicht -- er hat ein
 * Kästchen „Kurier/Melder". Sie gehört zum Auftrag, nicht zur Nachricht, und
 * nach der Lesart des Meldewesens wird dafür kein Merkmal angelegt: Der
 * Auftrag sagt dem Leiter des Fernmeldebetriebes, was er entscheidet, wenn
 * er jemanden losschickt.
 *
 * Die Pflichten des Melders stehen dagegen bereits im Bestand, und dieser
 * Test hält sie fest: Er meldet, wem er übergeben hat, er meldet sich
 * zurück, und bis dahin nimmt er keinen zweiten Auftrag an.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$page = file_get_contents($root . '/4fach/fuehrungsstelle.php');
$rules = file_get_contents(
    $root . '/docker/db/migrations/94-dv-organisational-controls.sql'
);
$operations = file_get_contents($root . '/app/dv_operations.php');
if (!is_string($page) || !is_string($rules) || !is_string($operations)) {
    throw new RuntimeException('Führungsstelle, Migration oder Betrieb nicht lesbar.');
}

/* --- Der Unterschied steht dort, wo jemand losgeschickt wird --- */

$assignment = substr(
    $page,
    (int) strpos($page, 'value="assign_messenger"'),
    3000
);
$assert(
    $assignment !== '',
    estab_dv_requirement(
        'TKM-MELDER-KURIER',
        'Die Beauftragung eines Melders ist nicht mehr auffindbar.'
    )
);
foreach (
    ['Melder' => 'den Melder', 'Kurier' => 'den Kurier',
        'Rückfrage' => 'die Rückfrage', 'Inhalt' => 'den Inhalt'] as $word => $what
) {
    $assert(
        str_contains($assignment, $word),
        estab_dv_requirement(
            'TKM-MELDER-KURIER',
            'Die Beauftragung nennt ' . $what . ' nicht. Wer einen Kurier '
                . 'losschickt und mit Rückfragen rechnet, wartet auf eine '
                . 'Antwort, die niemand geben kann.'
        )
    );
}

/*
 * Und der Hinweis sagt beides, nicht nur eines: Der Melder kennt den Inhalt,
 * der Kurier nicht. Ein Hinweis, der nur einen der beiden beschreibt, lässt
 * offen, was der andere ist.
 */
// Auszeichnung steht dazwischen: "<em>Melder</em> kennt den Inhalt".
$assert(
    preg_match('~Melder\b.{0,200}?\bkennt~us', $assignment) === 1
        && preg_match(
            '~Kurier\b.{0,200}?(?:kennt ihn nicht|verschlossen)~us',
            $assignment
        ) === 1,
    estab_dv_requirement(
        'TKM-MELDER-KURIER',
        'Die Beauftragung beschreibt nur eine der beiden Rollen; die andere '
            . 'bleibt offen.'
    )
);

/*
 * Kein neues Merkmal: Die Unterscheidung ist eine Entscheidung des LdF beim
 * Losschicken, keine Spalte am Auftrag. Nach der Lesart des Meldewesens
 * erzeugt Q3 keine Schemaänderung.
 */
foreach (['rolle', 'kurier', 'melder_art'] as $column) {
    $assert(
        !preg_match(
            '~`nv_melderauftraege`.*?`' . $column . '`~is',
            substr($rules, (int) strpos($rules, 'CREATE TABLE IF NOT EXISTS `nv_melderauftraege`'), 1600)
        ),
        estab_dv_requirement(
            'TKM-MELDER-KURIER',
            'Der Melderauftrag führt eine Spalte „' . $column . '“. Die '
                . 'Unterscheidung gehört in die Entscheidung des LdF, nicht '
                . 'in das Schema.'
        )
    );
}

/* --- Was der Melder schuldet, verlangt der Auftrag --- */

$table = substr(
    $rules,
    (int) strpos($rules, 'CREATE TABLE IF NOT EXISTS `nv_melderauftraege`'),
    1600
);
foreach (
    [
        '`tatsaechlicher_empfaenger`' => 'wem er übergeben hat',
        '`ruecknachricht`' => 'ob er eine Rücknachricht mitbringt',
        '`zurueck_am`' => 'wann er zurück war',
        '`gemeldet_an`' => 'bei wem er sich zurückgemeldet hat',
    ] as $column => $what
) {
    $assert(
        str_contains($table, $column),
        estab_dv_requirement(
            'TKM-MELDER-PFLICHTEN',
            'Der Melderauftrag hält nicht fest, ' . $what . '.'
        )
    );
}

/*
 * Und er nimmt keinen zweiten Auftrag an, bevor er zurück ist. Die
 * Datenbank hält das selbst fest: Ein erzeugter Wert führt den offenen
 * Melder, und ein eindeutiger Schlüssel darauf lässt keinen zweiten zu.
 */
$assert(
    str_contains($table, '`offener_melder`')
        && str_contains($table, "NOT IN ('GEMELDET','ABGEBROCHEN')"),
    estab_dv_requirement(
        'TKM-MELDER-PFLICHTEN',
        'Der Auftrag weist einen offenen Melder nicht aus; ein zweiter '
            . 'Auftrag liesse sich nicht verhindern.'
    )
);
$assert(
    preg_match('~UNIQUE[^,;]*\(\s*`einsatz_id`\s*,\s*`offener_melder`~i', $rules) === 1
        || preg_match('~UNIQUE[^,;]*`offener_melder`~i', $rules) === 1,
    estab_dv_requirement(
        'TKM-MELDER-PFLICHTEN',
        'Nichts hindert einen Melder daran, zwei Aufträge zugleich zu '
            . 'tragen. Bis zur Rückkehr nimmt er keinen weiteren an.'
    )
);

/*
 * Und der Inhalt bleibt, wie er ist: Der Melder überbringt, er ändert nicht.
 * Der Auftrag kennt deshalb keinen Weg, die Nachricht zu bearbeiten -- er
 * verweist auf sie.
 */
$assert(
    str_contains($table, '`nachricht_id`'),
    estab_dv_requirement(
        'TKM-MELDER-PFLICHTEN',
        'Der Auftrag verweist nicht auf die Nachricht, die er befördert.'
    )
);
$assert(
    !preg_match(
        '~function estab_dv_[a-z_]*messenger[a-z_]*\([^)]*\)[^{]*\{'
            . '(?:[^{}]|\{[^{}]*\})*UPDATE `?nv_nachrichten~is',
        $operations
    ),
    estab_dv_requirement(
        'TKM-MELDER-PFLICHTEN',
        'Der Melderauftrag schreibt an der Nachricht. Der Melder überbringt '
            . 'den Inhalt, er ändert ihn nicht.'
    )
);

printf("Melder und Kurier: OK (%d assertions)\n", $assertions);
