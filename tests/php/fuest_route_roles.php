<?php

declare(strict_types=1);

/**
 * Sichter und Leiter des Fernmeldebetriebes: zwei Stellen, zwei Zugehörigkeiten.
 *
 * Der Sichter ist Bindeglied zwischen Stab und Fernmeldezentrale und dem
 * Leiter des Stabes unterstellt -- nicht dem Fernmeldebetrieb. Das ist keine
 * Formalie: Wer die Nachrichten des Stabes prüft, darf nicht dem
 * unterstehen, dessen Betrieb er dabei kontrolliert. Umgekehrt verantwortet
 * der Leiter des Fernmeldebetriebes den Betrieb selbst; Beförderung und
 * Melderauftrag laufen über ihn.
 *
 * In einer kleinen Führungsstelle trägt eine Kraft oft beides: Sichtung und
 * Einsatztagebuch. Die Dienstvorschrift lässt das ausdrücklich zu, und die
 * Anwendung muss beide Zugehörigkeiten dann nebeneinander führen, statt eine
 * davon zu verlieren.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/auth.php';
require_once $root . '/app/workflow.php';
require_once $root . '/app/message_status.php';
require_once $root . '/app/message_transport.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Eine angemeldete Kraft mit einer Funktion. */
$identity = static function (string $function, string $role): array {
    return ['funktion' => $function, 'rolle' => $role, 'kuerzel' => 'mm'];
};

/** Dieselbe Kraft, die eine zweite Funktion angenommen hat. */
$doubleHat = static function (
    string $function,
    string $role,
    string $second,
    string $secondRole
): array {
    return [
        'funktion' => $function,
        'rolle' => $role,
        'kuerzel' => 'mm',
        'estab_permission_mode' => 'LOOSE',
        'estab_additional_functions' => [
            ['funktion' => $second, 'rolle' => $secondRole],
        ],
    ];
};

/* --- Der Sichter gehört dem Stab --- */

$viewer = $identity('Si', 'Stab');
$assert(
    estab_workflow_is_viewer($viewer),
    estab_dv_requirement(
        'FUEST-SICHTER-BINDEGLIED',
        'Der Sichter des Stabes wird nicht als Sichter erkannt.'
    )
);
$assert(
    !estab_workflow_is_viewer($identity('Si', 'Fernmelder')),
    estab_dv_requirement(
        'FUEST-SICHTER-BINDEGLIED',
        'Eine Kraft des Fernmeldebetriebes gilt als Sichter. Der Sichter '
            . 'ist dem Leiter des Stabes unterstellt.'
    )
);

// Der Sichter ist nicht der Fernmeldebetrieb, den er dabei prüft.
$assert(
    !estab_workflow_is_telecommunications($viewer)
        && !estab_workflow_is_telecommunications_lead($viewer),
    estab_dv_requirement(
        'FUEST-SICHTER-BINDEGLIED',
        'Der Sichter zählt zugleich zum Fernmeldebetrieb.'
    )
);

/* --- Doppelfunktion Sichtung und Einsatztagebuch --- */

foreach (['ETB', 'S2'] as $logbookFunction) {
    $both = $doubleHat('Si', 'Stab', $logbookFunction, 'Stab');
    $assert(
        estab_workflow_is_viewer($both),
        estab_dv_requirement(
            'FUEST-SICHTER-BINDEGLIED',
            'Wer neben der Sichtung ' . $logbookFunction . ' trägt, '
                . 'verliert die Sichtung.'
        )
    );
    $carried = array_map(
        static fn (array $tuple): string => $tuple['funktion'],
        estab_auth_effective_function_roles($both)
    );
    $assert(
        in_array('Si', $carried, true)
            && in_array($logbookFunction, $carried, true),
        estab_dv_requirement(
            'FUEST-SICHTER-BINDEGLIED',
            'Die Doppelfunktion Sichtung/' . $logbookFunction
                . ' wird nicht vollständig geführt: '
                . implode(', ', $carried)
        )
    );
}

/* --- Der Leiter des Fernmeldebetriebes verantwortet den Betrieb --- */

$lead = $identity('LdF', 'Fernmelder');
$assert(
    estab_workflow_is_telecommunications_lead($lead),
    estab_dv_requirement(
        'FUEST-LDF-BETRIEB',
        'Der Leiter des Fernmeldebetriebes wird nicht erkannt.'
    )
);
$assert(
    !estab_workflow_is_telecommunications_lead($identity('LdF', 'Stab')),
    estab_dv_requirement(
        'FUEST-LDF-BETRIEB',
        'Eine Kraft des Stabes führt den Fernmeldebetrieb.'
    )
);
$assert(
    !estab_workflow_is_telecommunications_lead($identity('A/W', 'Fernmelder')),
    estab_dv_requirement(
        'FUEST-LDF-BETRIEB',
        'Die Fernmeldezentrale führt sich selbst; die nachgeordnete '
            . 'Betriebsleitung entfiele.'
    )
);
$assert(
    !estab_workflow_is_viewer($lead),
    estab_dv_requirement(
        'FUEST-LDF-BETRIEB',
        'Der Leiter des Fernmeldebetriebes sichtet die Nachrichten, die '
            . 'sein eigener Betrieb bearbeitet hat.'
    )
);

/* --- Die Freigabe zur Beförderung liegt bei ihm, nicht bei der Zentrale --- */

$transitions = estab_message_status_transitions();
$stationFor = static function (
    string $direction,
    int $from,
    int $to
) use ($transitions): array {
    $stations = [];
    foreach ($transitions[$direction] ?? [] as $step) {
        if (($step['from'] ?? null) === $from && ($step['to'] ?? null) === $to) {
            $stations[] = $step['station'];
        }
    }
    return array_values(array_unique($stations));
};

$assert(
    $stationFor(
        'outgoing',
        ESTAB_MESSAGE_STATUS_LDF,
        ESTAB_MESSAGE_STATUS_TRANSPORT
    ) === ['LdF'],
    estab_dv_requirement(
        'FUEST-LDF-BETRIEB',
        'Die Freigabe zur Beförderung liegt nicht allein beim Leiter des '
            . 'Fernmeldebetriebes.'
    )
);

// Der Melderauftrag ist ein Beförderungsweg wie jeder andere: Er wird
// disponiert, nicht gewünscht. Der Verfasser äußert in Feld 7 nur den Wunsch.
$assert(
    !estab_message_desired_medium_editable('LdF-Ausgang'),
    estab_dv_requirement(
        'FUEST-LDF-BETRIEB',
        'Der Leiter des Fernmeldebetriebes überschreibt den Wunsch des '
            . 'Verfassers in Feld 7, statt den Weg zu disponieren.'
    )
);
$assert(
    estab_message_medium_storage_value('Me') === 'Me',
    estab_dv_requirement(
        'FUEST-LDF-BETRIEB',
        'Der Melder ist kein disponierbarer Beförderungsweg.'
    )
);

/*
 * Sichtung und Fernmeldebetrieb bleiben auch in Doppelfunktion getrennte
 * Zuständigkeiten: Wer beides trägt, ist in beiden Rollen erkennbar, aber
 * keine der beiden Prüfungen färbt auf die andere ab.
 */
$mixed = $doubleHat('Si', 'Stab', 'LdF', 'Fernmelder');
$assert(
    estab_workflow_is_viewer($mixed)
        && estab_workflow_is_telecommunications_lead($mixed),
    estab_dv_requirement(
        'FUEST-SICHTER-BINDEGLIED',
        'Eine Kraft, die Sichtung und Fernmeldebetrieb trägt, verliert eine '
            . 'der beiden Zuständigkeiten.'
    )
);
$assert(
    !estab_workflow_is_telecommunications($mixed),
    estab_dv_requirement(
        'FUEST-LDF-BETRIEB',
        'Die Betriebsleitung verleiht zugleich die Bedienung der '
            . 'Fernmeldezentrale.'
    )
);

printf("Sichter und Fernmeldebetrieb im Laufweg: OK (%d assertions)\n", $assertions);
