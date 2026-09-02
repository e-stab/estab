<?php

declare(strict_types=1);

/**
 * Der LdF vertritt den Fernmelder -- ohne einer zu werden.
 *
 * Der Leiter des Fernmeldebetriebs ist fuer den Betrieb zustaendig. Er muss
 * die Kennzahlen der Fernmelder ueberwachen und erkennen, wenn es klemmt --
 * und im Problemfall einzelne Aufgaben selbst uebernehmen. Dafuer braucht er
 * die Ansichten und die Arbeitsschritte des Annahme- und Weitergabeplatzes.
 *
 * Er wird dadurch **nicht** zu einem zweiten A/W. Das ist keine Feinheit:
 *
 *   - `workflow_security` verlangt namentlich, dass A/W- und LdF-Identitaeten
 *     strikt getrennt bleiben. Wer den LdF zum A/W macht, bricht das.
 *   - `FUEST-DOPPELFUNKTION` aus DV 1-101 weist jeder getragenen Funktion
 *     eine eigene Warteschlange zu. Ein LdF mit zwei Funktionen bekaeme zwei
 *     Warteschlangen -- und die Anzeige verwischte den Unterschied zwischen
 *     "leitet den Betrieb" und "ist an der Annahmestelle eingeteilt".
 *
 * Deshalb ein dritter Begriff neben "ist A/W" und "darf als A/W schreiben":
 * **darf fuer den A/W handeln**. Die Identitaet bleibt, die Erlaubnis waechst.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/auth.php';
require_once $root . '/app/workflow.php';
require_once $root . '/app/attachment.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$ldf = ['funktion' => 'LdF', 'rolle' => 'Fernmelder'];
$aw = ['funktion' => 'A/W', 'rolle' => 'Fernmelder'];
$s2 = ['funktion' => 'S2', 'rolle' => 'Stab'];
$si = ['funktion' => 'Si', 'rolle' => 'Stab'];

// Die Identitaeten bleiben getrennt. Das ist der Invariant, den der erste
// Anlauf gebrochen hat.
$assert(
    !estab_workflow_is_telecommunications($ldf),
    'Der LdF gilt als A/W. Die Identitaeten muessen getrennt bleiben -- '
        . 'sonst bekommt er zwei Warteschlangen (FUEST-DOPPELFUNKTION).'
);
$assert(
    !estab_workflow_is_telecommunications_lead($aw),
    'Der A/W gilt als LdF.'
);
$assert(
    estab_workflow_is_telecommunications($aw)
        && estab_workflow_is_telecommunications_lead($ldf),
    'Eine der beiden Funktionen erkennt sich selbst nicht mehr.'
);

// Die Vertretung: der LdF darf fuer den A/W handeln, der A/W nicht fuer den LdF.
$assert(
    estab_workflow_may_act_for_telecommunications($ldf),
    'Der LdF darf nicht fuer den Fernmelder handeln. Er kann dann bei einem '
        . 'Problem nicht einspringen -- und im Einsatz sitzt er oft allein.'
);
$assert(
    estab_workflow_may_act_for_telecommunications($aw),
    'Der A/W darf nicht fuer sich selbst handeln.'
);
$assert(
    !estab_workflow_may_act_for_telecommunications($s2)
        && !estab_workflow_may_act_for_telecommunications($si),
    'Eine Stabsfunktion darf fuer den Fernmelder handeln. Die Vertretung '
        . 'gehoert zur Betriebsleitung, nicht zum Stab.'
);

/*
 * Die Arbeitsschritte des A/W stehen dem LdF offen. Geprueft am Tor, nicht
 * an der Seitenspalte: Ein Knopf, den das Tor abweist, ist eine Sackgasse.
 */
foreach (['fm_eingang_x', 'fm_ausgang_x', 'fm_admin_x', 'fm_anhang_x'] as $schritt) {
    $assert(
        estab_workflow_route_allowed($ldf, 'POST', [$schritt => '1']),
        'Das Tor weist dem LdF den Arbeitsschritt ' . $schritt . ' ab.'
    );
    $assert(
        estab_workflow_route_allowed($aw, 'POST', [$schritt => '1']),
        'Das Tor weist dem A/W seinen eigenen Schritt ' . $schritt . ' ab.'
    );
    $assert(
        !estab_workflow_route_allowed($s2, 'POST', [$schritt => '1']),
        'Das Tor laesst S2 den Fernmelderschritt ' . $schritt . ' zu.'
    );
}

/*
 * Ein Arbeitsschritt ist erst dann offen, wenn auch der Vordruck dahinter
 * ihn annimmt. Sonst fuehrt der Knopf auf eine Maske, die jede Eingabe mit
 * "Aktion nicht erlaubt" abweist -- und deren Anlagenfeld sich gar nicht
 * erst binden laesst. Geprueft wird deshalb nicht nur das Tor zur Seite,
 * sondern auch das Tor zum Formular.
 */
foreach (['FM-Eingang', 'FM-Eingang_Anhang', 'FM-Ausgang'] as $vordruck) {
    $assert(
        estab_workflow_route_allowed($ldf, 'POST', ['task' => $vordruck]),
        'Der Vordruck ' . $vordruck . ' weist den LdF ab. Der Weg dorthin '
            . 'steht ihm offen -- der Schritt endet dann in einer Sackgasse.'
    );
    $assert(
        estab_workflow_route_allowed($aw, 'POST', ['task' => $vordruck]),
        'Der Vordruck ' . $vordruck . ' weist den A/W ab.'
    );
    $assert(
        !estab_workflow_route_allowed($s2, 'POST', ['task' => $vordruck]),
        'Der Vordruck ' . $vordruck . ' nimmt S2 an.'
    );
}

/*
 * Eine Liste, die sich nicht filtern laesst, ist keine Ansicht. Der Filter
 * gehoert zum Arbeitsschritt, nicht zur Funktion A/W.
 */
$assert(
    estab_workflow_route_allowed(
        $ldf,
        'POST',
        ['fm_admin_x' => '1', 'ml_apply' => '1', 'ml_q' => 'Lage']
    ),
    'Der LdF darf die Nachrichtenliste des A/W nicht filtern.'
);
$assert(
    !estab_workflow_route_allowed(
        $s2,
        'POST',
        ['fm_admin_x' => '1', 'ml_apply' => '1', 'ml_q' => 'Lage']
    ),
    'S2 darf die Nachrichtenliste des A/W filtern.'
);

/*
 * Die Anlage gehoert zum Vordruck: Wer ihn ausfuellen darf, haengt auch an.
 * Ohne diesen Vorgang meldet die Maske, der direkte Upload lasse sich nicht
 * sicher vorbereiten -- ein Satz ueber die Sicherheit, wo in Wahrheit die
 * Vertretung fehlt.
 */
foreach (['FM-Eingang', 'FM-Eingang_Anhang'] as $vordruck) {
    $assert(
        estab_attachment_origin_role_allowed($ldf, $vordruck),
        'Der LdF bekommt fuer ' . $vordruck . ' keinen Anhangvorgang.'
    );
    $assert(
        estab_attachment_origin_role_allowed($aw, $vordruck),
        'Der A/W bekommt fuer ' . $vordruck . ' keinen Anhangvorgang.'
    );
    $assert(
        !estab_attachment_origin_role_allowed($s2, $vordruck),
        'S2 bekommt fuer ' . $vordruck . ' einen Anhangvorgang.'
    );
}

// Die Disposition bleibt beim LdF. Die Vertretung gilt nur in eine Richtung.
$assert(
    estab_workflow_route_allowed($ldf, 'POST', ['ldf_nachrichten_x' => '1']),
    'Der LdF kommt nicht mehr an seine eigene Disposition.'
);
$assert(
    !estab_workflow_route_allowed($aw, 'POST', ['ldf_nachrichten_x' => '1']),
    'Der A/W kommt an die Disposition. Wer die Handgriffe tut, leitet '
        . 'deshalb nicht den Betrieb.'
);

printf("LdF vertritt den Fernmelder: OK (%d assertions)\n", $assertions);
