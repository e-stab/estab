<?php

declare(strict_types=1);

/**
 * Feld 19 on an incoming message: the sighting names a processing recipient.
 *
 * The telecommunications post already stamps the red Lage/Dokumentation copy
 * onto every incoming message, so a non-empty distribution list proves
 * nothing. Only a blue copy names someone who has to work the message, and
 * the sighting is the stage that closes the record for good.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/workflow.php';
require_once $root . '/4fach/vali_data.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$matrix = [];
for ($row = 1; $row <= 5; $row++) {
    for ($column = 1; $column <= 4; $column++) {
        $matrix[$row][$column] = ['fkt' => '', 'rolle' => ''];
    }
}
$matrix[1][1] = ['fkt' => 'S2', 'rolle' => 'S'];
$matrix[2][3] = ['fkt' => 'S3', 'rolle' => 'S'];

// The server writes the red copy for every incoming message on its own.
$mandatoryRedCopy = estab_workflow_distribution_tokens([], $matrix, ['S2_rt']);
$assert(
    $mandatoryRedCopy === 'S2_rt,',
    'The mandatory red copy no longer has the token form <funktion>_rt: '
        . $mandatoryRedCopy
);
$assert(
    !estab_workflow_distribution_has_processor($mandatoryRedCopy),
    estab_dv_requirement(
        'NV-19-VERTEILER-EINGANG',
        'Die vom Server gesetzte rote Durchschrift "' . $mandatoryRedCopy
            . '" gilt bereits als benannter Empfänger'
    )
);

// A recipient the Sichter picks in the matrix carries the blue copy.
$withProcessor = estab_workflow_distribution_tokens(
    ['16_23' => '16_23_bl'],
    $matrix,
    ['S2_rt']
);
$assert(
    $withProcessor === 'S2_rt,S3_bl,',
    'A chosen recipient no longer has the token form <funktion>_bl: '
        . $withProcessor
);
$assert(
    estab_workflow_distribution_has_processor($withProcessor),
    estab_dv_requirement(
        'NV-19-VERTEILER-EINGANG',
        'Ein im Verteiler angekreuzter Bearbeiter "' . $withProcessor
            . '" zählt nicht als benannter Empfänger'
    )
);
foreach (['', 'S2_rt,S3_gn,', 'S3_BL,', 'bl,', '_bl_,'] as $withoutProcessor) {
    $assert(
        !estab_workflow_distribution_has_processor($withoutProcessor),
        estab_dv_requirement(
            'NV-19-VERTEILER-EINGANG',
            'Der Verteiler "' . $withoutProcessor . '" benennt keinen '
                . 'Bearbeiter, wird aber als Empfängerliste akzeptiert'
        )
    );
}

// The legacy gate includes two runtime configuration files by a path relative
// to the working directory. Running from a directory where they resolve would
// open a database connection, so the gate is exercised from a directory where
// they cannot resolve and only their include warnings are silenced — every
// other diagnostic still surfaces.
$sightingComplete = static function (array $data): bool {
    $validator = new vali_data_form($data);
    $validator->checkallfields();
    $validator->validate['15_quitdatum'] = true;
    $validator->validate['15_quitzeichen'] = true;
    $workingDirectory = getcwd();
    chdir(sys_get_temp_dir());
    set_error_handler(static function (int $severity, string $message): bool {
        return $severity === E_WARNING && str_contains($message, '4fcfg/');
    });
    try {
        return (bool) $validator->checkdata();
    } finally {
        restore_error_handler();
        if (is_string($workingDirectory)) {
            chdir($workingDirectory);
        }
    }
};

$assert(
    !$sightingComplete([
        'task' => 'Stab_sichten',
        '04_richtung' => 'E',
        '16_empf' => 'S2_rt,',
    ]),
    estab_dv_requirement(
        'NV-19-VERTEILER-EINGANG',
        'Die Sichtung eines Eingangs lässt sich abschließen, obwohl der '
            . 'Verteiler nur die rote Durchschrift trägt'
    )
);
$assert(
    !$sightingComplete([
        'task' => 'Stab_sichten',
        '04_richtung' => 'E',
        '16_empf' => '',
    ]),
    estab_dv_requirement(
        'NV-19-VERTEILER-EINGANG',
        'Die Sichtung eines Eingangs lässt sich mit leerem Verteiler '
            . 'abschließen'
    )
);
$assert(
    $sightingComplete([
        'task' => 'Stab_sichten',
        '04_richtung' => 'E',
        '16_empf' => 'S2_rt,S3_bl,',
    ]),
    estab_dv_requirement(
        'NV-19-VERTEILER-EINGANG',
        'Eine Sichtung mit benanntem Bearbeiter wird abgewiesen'
    )
);
// The outgoing review is formal only; it never routes recipients.
$assert(
    $sightingComplete([
        'task' => 'Stab_sichten',
        '04_richtung' => 'A',
        '16_empf' => '',
    ]),
    estab_dv_requirement(
        'NV-19-VERTEILER-EINGANG',
        'Die formale Prüfung einer Ausgangsnachricht verlangt einen '
            . 'Verteilereintrag'
    )
);

// The save boundary refuses before it writes the completion, not after.
$handler = file_get_contents($root . '/4fach/data_hndl.php');
$assert(is_string($handler), 'The message handler could not be read');
$reviewCase = strrpos($handler, 'case "Stab_sichten":');
$assert(is_int($reviewCase), 'The sighting stage is gone from the handler');
$incoming = strpos($handler, 'if ($reviewDirection === "E") {', $reviewCase);
$assert(is_int($incoming), 'The incoming sighting branch is gone');
$gate = strpos(
    $handler,
    'estab_workflow_distribution_has_processor ($data ["16_empf"])',
    $incoming
);
$completion = strpos($handler, '$reviewFields ["x01_abschluss"] = "t";', $incoming);
$assert(
    is_int($completion),
    'The incoming sighting no longer completes the message'
);
$assert(
    is_int($gate) && $gate < $completion,
    estab_dv_requirement(
        'NV-19-VERTEILER-EINGANG',
        'Die Speicherung schließt den Eingang ab, ohne vorher einen '
            . 'Bearbeiter im Verteiler zu verlangen'
    )
);
$refusal = is_int($gate) ? strpos($handler, 'exit;', $gate) : false;
$assert(
    is_int($refusal) && $refusal < $completion,
    estab_dv_requirement(
        'NV-19-VERTEILER-EINGANG',
        'Die Sichtung ohne Bearbeiter laeuft nach der Pruefung weiter, '
            . 'statt den Abschluss abzubrechen'
    )
);
$assert(
    is_int(strpos(
        $handler,
        'estab_workflow_missing_processor_message ()',
        $incoming
    )),
    estab_dv_requirement(
        'NV-19-VERTEILER-EINGANG',
        'Die abgewiesene Sichtung nennt dem Sichter nicht, was fehlt'
    )
);

$message = estab_workflow_missing_processor_message();
foreach (['Feld 19', 'Bearbeiter', 'Verteiler', 'rote Durchschrift'] as $term) {
    $assert(
        str_contains($message, $term),
        estab_dv_requirement(
            'NV-19-VERTEILER-EINGANG',
            'Die Fehlermeldung an den Sichter erwähnt "' . $term . '" nicht'
        )
    );
}

printf(
    "Incoming sighting recipient: OK (%d assertions)\n",
    $assertions
);
