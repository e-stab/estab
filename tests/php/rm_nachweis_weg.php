<?php

declare(strict_types=1);

/**
 * Der Nachweis nennt den Weg, über den die Meldung wirklich ging.
 *
 * > „sobald es befördert wurde den beförderungsweg, wenn es noch nicht
 * > befördert ist gibt es ja noch kein beförderungsweg, dann das
 * > wunschmittel anzeigen vom verfasser"
 *
 * Der Vordruck trägt zwei Angaben, und sie können auseinanderfallen:
 *
 * - Feld 1 ist beim **Eingang** das Mittel, über das die Meldung
 *   hereinkam -- eine Tatsache. Beim **Ausgang** ist es das Mittel, das
 *   der Verfasser sich wünscht -- eine Bitte an die Fernmeldestelle.
 * - Feld 6 ist der Beförderungsweg, den die Leitung des Fernmeldebetriebs
 *   tatsächlich gewählt hat. Er steht erst da, wenn befördert wurde.
 *
 * Ein Nachweis, der beides gleich darstellt, behauptet einen Weg, den es
 * vielleicht nie gab. Deshalb wird der Wunsch als Wunsch gekennzeichnet.
 * Im Bestand ist der Fall nicht selten: 120 Ausgänge tragen ein
 * Wunschmittel ohne Beförderungsweg.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once $root . '/app/message_list_ui.php';
require_once $root . '/app/nachweisung.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Eine Nachweiszeile aus den Feldern bauen, die hier zählen. */
$zeile = static fn (array $felder): array => estab_nachweisung_zeile(array_merge([
    '00_lfd' => '1',
    '04_richtung' => 'A',
    '09_vorrangstufe' => 'sss',
    '10_anschrift' => 'ZUG 1',
    '13_abseinheit' => 'Führungsstelle',
    '12_abfzeit' => '',
    '12_inhalt' => 'Inhalt',
    '12_anhang' => '',
    '01_medium' => '',
    '06_befweg' => '',
    '06_befwegausw' => '',
], $felder));

// Befördert: der tatsächliche Weg.
$befoerdert = $zeile(['01_medium' => 'Fe', '06_befwegausw' => 'Fu']);
$assert(
    $befoerdert['mittel'] === 'Funk',
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Der Nachweis nennt nicht den Weg, über den befördert wurde: '
            . $befoerdert['mittel']
    )
);

/*
 * Noch nicht befördert: das Wunschmittel, als Wunsch gekennzeichnet --
 * aber nur in der Anzeige.
 *
 * Der Wert der Zelle bleibt das blanke Mittel. Das Bauteil filtert auf
 * Gleichheit; stuende "Funk (gewünscht)" im Wert, fiele die Zeile aus
 * dem Filter "Funk" heraus, und ein Fernmelder, der nach Funk filtert,
 * verlöre genau die Aufträge, die noch offen sind.
 */
$offen = $zeile(['01_medium' => 'Fu', '06_befwegausw' => '']);
$assert(
    $offen['mittel'] === 'Funk',
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Der Wert der Wegspalte trägt die Kennzeichnung und fällt damit aus '
            . 'dem Filter seines eigenen Mittels: ' . $offen['mittel']
    )
);
$assert(
    ($offen['wunsch'] ?? false) === true,
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Ein noch nicht beförderter Ausgang ist nicht als Wunsch erkennbar.'
    )
);
$wegspalte = null;
foreach (estab_nachweisung_spalten(true) as $spalte) {
    if (($spalte['schluessel'] ?? '') === 'mittel') {
        $wegspalte = $spalte;
    }
}
$angezeigt = is_array($wegspalte) && isset($wegspalte['zelle'])
    ? ($wegspalte['zelle'])($offen)
    : '';
$assert(
    str_contains($angezeigt, 'Funk')
        && str_contains($angezeigt, 'gewünscht'),
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Der angezeigte Weg eines noch nicht beförderten Ausgangs liest '
            . 'sich wie ein tatsächlicher Weg: ' . $angezeigt
    )
);
$angezeigtBefoerdert = is_array($wegspalte) && isset($wegspalte['zelle'])
    ? ($wegspalte['zelle'])($befoerdert)
    : '';
$assert(
    !str_contains($angezeigtBefoerdert, 'gewünscht'),
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Ein beförderter Weg wird als Wunsch gekennzeichnet: '
            . $angezeigtBefoerdert
    )
);

// Weder das eine noch das andere: nichts erfinden.
$leer = $zeile(['01_medium' => '', '06_befwegausw' => '']);
$assert(
    trim($leer['mittel']) === '',
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Ohne Angabe erfindet der Nachweis ein Mittel: ' . $leer['mittel']
    )
);

/*
 * Beim Eingang ist Feld 1 keine Bitte, sondern das Mittel, über das die
 * Meldung hereinkam. Es wird nicht als Wunsch gekennzeichnet -- das wäre
 * eine Behauptung über den Absender, die niemand aufgestellt hat.
 */
$eingang = $zeile(['04_richtung' => 'E', '01_medium' => 'Fu', '06_befwegausw' => '']);
$assert(
    $eingang['mittel'] === 'Funk' && ($eingang['wunsch'] ?? false) === false,
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Ein Eingangsmittel wird als Wunsch ausgegeben, obwohl es die '
            . 'Tatsache ist, über die die Meldung hereinkam: '
            . $eingang['mittel']
    )
);

/*
 * Und die Spalte heißt nach dem, was sie zeigt. „Mittel" allein liesse
 * offen, ob der Wunsch oder der Weg gemeint ist.
 */
$spalten = estab_nachweisung_spalten(true);
$mittelspalte = null;
foreach ($spalten as $spalte) {
    if (($spalte['schluessel'] ?? '') === 'mittel') {
        $mittelspalte = $spalte;
    }
}
$assert(
    is_array($mittelspalte)
        && str_contains((string) $mittelspalte['kopf'], 'Weg'),
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Die Spalte sagt nicht, dass sie den Beförderungsweg zeigt: '
            . (string) ($mittelspalte['kopf'] ?? '—')
    )
);
$assert(
    is_array($mittelspalte) && ($mittelspalte['filter'] ?? []) !== [],
    estab_ux_requirement(
        'UX-AUSGANG-MEDIUM',
        'Der Nachweis lässt sich nicht nach dem Weg filtern.'
    )
);

/*
 * Der Filter bietet genau die Woerter an, die in der Spalte stehen.
 *
 * Er bot einmal eine eigene Liste -- 'Funk', 'Telefon', 'Telefax',
 * 'DFUE', 'Kurier/Melder' --, waehrend die Spalte 'Funk',
 * 'Fernsprecher', 'Melder', 'Fax', 'Fernschreiber' und
 * 'Datenuebertragung' zeigte. Das Bauteil filtert auf Gleichheit: Von
 * fuenf angebotenen Werten traf genau einer je zu. Wer nach 'Telefon'
 * filterte, bekam eine leere Liste und keinen Hinweis, warum.
 */
$angeboten = estab_nachweisung_mittel();
foreach (['Fu', 'Fe', 'Me', 'FAX', 'FS', '@'] as $kuerzel) {
    $wort = estab_message_medium_text($kuerzel);
    $assert(
        $wort !== '' && in_array($wort, $angeboten, true),
        estab_ux_requirement(
            'UX-AUSGANG-MEDIUM',
            'Der Filter der Nachweisung bietet "' . $wort . '" nicht an, '
                . 'obwohl die Spalte es zeigt. Wer danach filtert, bekaeme '
                . 'eine leere Liste und keinen Hinweis, warum.'
        )
    );
}
foreach ($angeboten as $wort) {
    $trifft = false;
    foreach (['Fu', 'Fe', 'Me', 'FAX', 'FS', '@'] as $kuerzel) {
        if (estab_message_medium_text($kuerzel) === $wort) {
            $trifft = true;
            break;
        }
    }
    $assert(
        $trifft,
        estab_ux_requirement(
            'UX-AUSGANG-MEDIUM',
            'Der Filter bietet "' . $wort . '" an, was in keiner Zeile '
                . 'stehen kann.'
        )
    );
}

printf("Nachweis Beförderungsweg: OK (%d assertions)\n", $assertions);
