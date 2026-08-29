<?php

declare(strict_types=1);

/**
 * Der Nachrichtenlauf hält, auch ohne JavaScript.
 *
 * Eine Führungsstelle arbeitet auf Geräten, die sie nicht selbst
 * ausgesucht hat: einem alten Dienstlaptop, einem Browser mit strengen
 * Einstellungen, einem Rechner, auf dem das Skript aus irgendeinem Grund
 * nicht lädt. Wenn dann eine Meldung nicht abzusetzen ist, greift jemand zum
 * Papier -- und die Nachweisführung ist dahin.
 *
 * Komfort darf am Skript hängen: die Hilfeblasen, der Zeitstempel auf
 * Knopfdruck, die Warnung vor ungespeicherten Eingaben. Der Laufweg nicht.
 * Aufnehmen, Sichten und Befördern müssen ohne eine Zeile JavaScript gehen.
 *
 * Geprüft wird das an der Stelle, an der es entschieden wird: Jede Handlung
 * des Laufwegs ist ein gewöhnliches Formular mit gewöhnlichen
 * Absendeknöpfen, und der Server nimmt sie entgegen, ohne nach dem Skript zu
 * fragen.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$view = file_get_contents($root . '/4fach/official_message_form.php');
/*
 * Der Knopfdruck wird an drei Stellen erwartet: im Steuerteil, in der
 * Datenstrecke und in der Ablaufsteuerung. Gelesen werden alle drei --
 * entscheidend ist, dass ein abgesendetes Formular irgendwo ankommt, nicht
 * wo.
 */
$handler = '';
foreach (
    ['4fach/mainindex.php', '4fach/data_hndl.php', 'app/workflow.php']
    as $file
) {
    $contents = file_get_contents($root . '/' . $file);
    if (!is_string($contents)) {
        throw new RuntimeException('Nicht lesbar: ' . $file);
    }
    $handler .= $contents;
}
if (!is_string($view)) {
    throw new RuntimeException('Der Vordruck ist nicht lesbar.');
}

/* --- Der Vordruck ist ein gewöhnliches Formular --- */

$render = substr(
    $view,
    (int) strpos($view, 'function plot_official_message_form()')
);
$assert(
    str_contains($render, '<form method="post"')
        && str_contains($render, 'enctype="multipart/form-data"')
        && str_contains($render, 'action="'),
    estab_ux_requirement(
        'UX-OHNE-JAVASCRIPT',
        'Der Vordruck ist kein gewöhnliches Formular mit Ziel und Methode; '
            . 'ohne Skript ginge nichts ab.'
    )
);

/*
 * Jede Handlung des Laufwegs wird von einem Absendeknopf ausgelöst. Ein
 * Knopf vom Typ "button" braucht ein Skript, das ihn abhört -- ohne Skript
 * tut er nichts.
 */
$actions = [
    'absenden_x' => 'Absenden und Abschliessen',
    'abbrechen_x' => 'Abbrechen',
    'zurueckweisen_x' => 'Rückweisung durch den Sichter',
    'ldf_zurueckweisen_x' => 'Rückgabe durch den LdF',
    'transport_nicht_moeglich_x' => 'Beförderung nicht möglich',
    'gelesen_x' => 'Gelesen',
    'antwort_x' => 'Antworten',
    'weiterleiten_x' => 'Weiterleiten',
];
foreach ($actions as $name => $what) {
    $assert(
        str_contains($view, "'" . $name . "'"),
        estab_ux_requirement(
            'UX-OHNE-JAVASCRIPT',
            'Die Handlung „' . $what . '“ hat keinen Absendeknopf mehr.'
        )
    );
    $assert(
        str_contains($handler, $name),
        estab_ux_requirement(
            'UX-OHNE-JAVASCRIPT',
            'Die Speicherstrecke nimmt „' . $what . '“ nicht entgegen; der '
                . 'Knopf ginge ins Leere.'
        )
    );
}

/*
 * Und die Knöpfe sind Absendeknöpfe. Der einzige, der es nicht ist, ist
 * Drucken -- Drucken ändert nichts an der Nachricht, und ohne Skript bleibt
 * der Weg über das Menü des Browsers.
 */
preg_match_all(
    '~official_message_action_button\(\s*\n?\s*\'([a-z]+)\','
        . '\s*\n?\s*\'([^\']*)\',\s*\n?\s*\'([a-z]+)\'~',
    $view,
    $buttons,
    PREG_SET_ORDER
);
$assert(
    count($buttons) >= 8,
    estab_ux_requirement(
        'UX-OHNE-JAVASCRIPT',
        'Es liessen sich nur ' . count($buttons) . ' Knöpfe auffinden.'
    )
);
foreach ($buttons as [, $role, $label, $type]) {
    $assert(
        $type === 'submit' || $label === 'Drucken',
        estab_ux_requirement(
            'UX-OHNE-JAVASCRIPT',
            'Der Knopf „' . $label . '“ ist vom Typ ' . $type . ' und '
                . 'braucht ein Skript, das ihn abhört.'
        )
    );
}

/* --- Die Skripte sind Zugaben, keine Voraussetzung --- */

/*
 * Ein Skript, das erst Felder einsetzt, macht sich zur Voraussetzung. Die
 * mitgelieferten Skripte dürfen deshalb nichts erzeugen, was zum Absenden
 * gebraucht wird -- sie dürfen nur schon Vorhandenes bequemer machen.
 */
preg_match_all(
    '~<script[^>]*data-estab-([a-z-]+)~',
    $view,
    $scripts
);
$assert(
    $scripts[1] !== [],
    estab_ux_requirement(
        'UX-OHNE-JAVASCRIPT',
        'Die Skripte des Vordrucks lassen sich nicht benennen.'
    )
);
$comfort = [
    'attachment-presentation', 'attachment-upload-limit',
    'official-form-help', 'official-form-focus',
    // Ein zweiter Klick nimmt ein gesetztes Kreuz wieder weg. Ein
    // Auswahlknopf laesst sich mit den Mitteln des Browsers anwaehlen, aber
    // nicht abwaehlen -- wer sich vertippt, bekommt das Kreuz nicht mehr
    // weg, und es steht spaeter als Angabe des Verfassers im Nachweis.
    //
    // Komfort im Sinne der Regel: Ohne Skript bleibt es beim Verhalten des
    // Browsers, und der Weg, den es dann braucht -- den Vordruck neu laden
    // --, war vorher der einzige.
    'official-choice-toggle',
];
foreach ($scripts[1] as $script) {
    $assert(
        in_array($script, $comfort, true),
        estab_ux_requirement(
            'UX-OHNE-JAVASCRIPT',
            'Der Vordruck liefert das unbekannte Skript „' . $script
                . '“ aus. Ob der Laufweg ohne es hält, ist damit '
                . 'unbeantwortet.'
        )
    );
}

// Und keines von ihnen legt ein Feld an, das der Server verlangt.
foreach (
    ['createElement("input")', "createElement('input')",
        'insertAdjacentHTML', 'innerHTML ='] as $creation
) {
    $assert(
        !str_contains($view, $creation),
        estab_ux_requirement(
            'UX-OHNE-JAVASCRIPT',
            'Ein Skript des Vordrucks erzeugt Markup (' . $creation
                . '); ohne Skript fehlte es.'
        )
    );
}

/* --- Der Server verlangt kein Skript --- */

/*
 * Die Prüfung der Eingaben läuft auf dem Server. Liefe sie nur im Browser,
 * wäre sie ohne Skript weg -- und mit ihr der Schutz, nicht nur der Komfort.
 */
$validator = file_get_contents($root . '/4fach/vali_data.php');
$assert(
    is_string($validator) && str_contains($validator, 'function checkallfields'),
    estab_ux_requirement(
        'UX-OHNE-JAVASCRIPT',
        'Die Eingabeprüfung findet nicht mehr auf dem Server statt.'
    )
);

/*
 * Und der Zeitstempel: Der Knopf setzt die Zeit bequem ein, aber das Feld
 * lässt sich auch von Hand füllen. Ein Feld, das nur ein Skript füllen kann,
 * waere ohne Skript ein leeres Pflichtfeld.
 */
$assert(
    !preg_match(
        '~<input[^>]*name="1[256]_[a-z]+"[^>]*\breadonly\b~',
        $view
    ),
    estab_ux_requirement(
        'UX-OHNE-JAVASCRIPT',
        'Ein Zeitfeld ist schreibgeschützt und liesse sich ohne Skript '
            . 'nicht füllen.'
    )
);

printf("Laufweg ohne JavaScript: OK (%d assertions)\n", $assertions);
