<?php

declare(strict_types=1);

/**
 * Die Meldungsuebersicht steht jeder Funktion offen.
 *
 * Sie war der Lage- und Dokumentationsfunktion vorbehalten. Wer sie aufrief,
 * bekam: "Die Meldungsuebersicht ist der aktiven Lage/Dokumentation
 * vorbehalten." Betreiberentscheidung: Jeder soll die Meldungen ansehen
 * koennen.
 *
 * Geoeffnet wird **nur das Lesen**. Die Uebersicht ist eine Ansicht; sie
 * schreibt nichts. Jede schreibende Pruefung bleibt, wo sie ist, und dieser
 * Test verlangt das ausdruecklich -- eine Leseerweiterung, die nebenbei ein
 * Schreibrecht aufmacht, waere der schwerste Fehler, den man hier machen
 * kann.
 *
 * Der Abfrageumfang der Uebersicht war nie enger als der Einsatz: Sie filtert
 * auf `einsatz_id` und sonst nichts. Zu oeffnen war deshalb nur das Tor.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/read_authorization.php';
require_once $root . '/app/workflow.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$quelle = file_get_contents($root . '/app/read_authorization.php');
$assert(is_string($quelle), 'read_authorization.php ist nicht lesbar.');
$quelle = (string) $quelle;

// Das Tor verlangt keine Fachzustaendigkeit mehr, sondern nur noch einen
// gueltigen Dienst im aktiven Einsatz.
$assert(
    preg_match(
        '~\$area === \'message-overview\'.*?estab_read_require_identity_scope~s',
        $quelle
    ) === 1,
    'Die Meldungsuebersicht verlangt weiterhin eine bestimmte '
        . 'Fachzustaendigkeit statt nur einen gueltigen Dienst.'
);
$assert(
    preg_match(
        '~\$area === \'message-overview\'.*?LAGE_DOKUMENTATION~s',
        $quelle
    ) !== 1,
    'Die Meldungsuebersicht haengt noch an LAGE_DOKUMENTATION.'
);

// Die Nachweisung bleibt, wo sie war. Sie ist eine andere Ansicht mit einer
// anderen Frage, und die Rueckmeldung betraf sie nicht.
$assert(
    str_contains($quelle, 'Die Nachweisung ist nur für LdF oder Fernmelder verfügbar.'),
    'Die Nachweisung hat ihre eigene Beschraenkung verloren. Geoeffnet '
        . 'werden sollte die Uebersicht, nicht jede Ansicht.'
);

/*
 * Kein Schreibrecht ist mitgegangen. Geprueft am Tor des Nachrichtenlaufs:
 * Eine Stabsfunktion darf weiterhin nicht die Schritte des Fernmelders, und
 * der Fernmelder nicht die des Stabs.
 */
$s2 = ['funktion' => 'S2', 'rolle' => 'Stab'];
$aw = ['funktion' => 'A/W', 'rolle' => 'Fernmelder'];
$assert(
    !estab_workflow_route_allowed($s2, 'POST', ['fm_eingang_x' => '1']),
    'Die Leseoeffnung hat S2 einen Fernmelderschritt aufgemacht.'
);
$assert(
    !estab_workflow_route_allowed($aw, 'POST', ['stab_schreiben_x' => '1']),
    'Die Leseoeffnung hat dem Fernmelder einen Stabsschritt aufgemacht.'
);

// Und die Abweisung, die niemand mehr sehen soll, ist verschwunden.
$seite = file_get_contents($root . '/4fueltg/ue_ltg.php');
$assert(is_string($seite), 'ue_ltg.php ist nicht lesbar.');
$assert(
    !str_contains(
        (string) $seite,
        'Die Meldungsübersicht ist der aktiven Lage/Dokumentation vorbehalten.'
    ),
    'Die alte Abweisung steht noch in der Seite.'
);

printf("Uebersicht fuer alle: OK (%d assertions)\n", $assertions);
