<?php

declare(strict_types=1);

/**
 * Feld 19 on an outgoing message: the author fills in the distribution.
 *
 * The official form carries one distributor for incoming and outgoing
 * messages. Who inside the staff works an outgoing message is the author's
 * decision, so the boxes have to be reachable while the message is written or
 * corrected. Two copies stay server-owned evidence and cannot be unchecked:
 * the red Lage/Dokumentation copy and the author's own green copy. Nothing
 * forces the author to name anybody else — a command post that does not staff
 * every position must still be able to send.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/workflow.php';
require_once $root . '/app/function_label.php';
require_once $root . '/4fach/vali_data.php';

$previousDirectory = getcwd();
if (!chdir($root . '/4fach')) {
    throw new RuntimeException('Could not enter the message form directory');
}
try {
    require_once $root . '/4fach/4fachform.php';
} finally {
    if (is_string($previousDirectory)) {
        chdir($previousDirectory);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** One recipient matrix: S2 keeps the red copy, S3 is a selectable staff cell. */
$matrix = [];
for ($row = 1; $row <= 5; $row++) {
    for ($column = 1; $column <= 4; $column++) {
        $matrix[$row][$column] = [
            'typ' => 't',
            'fkt' => '',
            'rolle' => 'leer',
            'mode' => 'ro',
            'auto' => 'f',
        ];
    }
}
$matrix[1][1] = ['typ' => 't', 'fkt' => 'S2', 'rolle' => 'Stab', 'mode' => 'rw', 'auto' => 'f'];
$matrix[2][3] = ['typ' => 't', 'fkt' => 'S3', 'rolle' => 'Stab', 'mode' => 'rw', 'auto' => 'f'];

/** Build one outgoing form exactly as the legacy controller does. */
$outgoingForm = static function (string $task) use ($matrix): nachrichten4fach {
    $form = (new ReflectionClass(nachrichten4fach::class))
        ->newInstanceWithoutConstructor();
    $form->task = $task;
    $form->formdata = [
        '04_richtung' => 'A',
        '16_empf' => 'S2_rt,S1_gn,',
        '12_anhang' => '',
    ];
    $form->empfarray = $matrix;
    $form->feldbgcolor();
    $form->get_access_by_task();
    return $form;
};

// The author writes the message and may correct it after a formal return.
// Both stages are the author's stages, so both release Feld 19.
foreach (['Stab_schreiben', 'Stab_korrigieren'] as $task) {
    $form = $outgoingForm($task);
    $assert(
        ($form->feld[16] ?? false) === true,
        estab_dv_requirement(
            'NV-19-VERTEILER-AUSGANG',
            'Der Vordruck "' . $task . '" gibt Feld 19 nicht zur Eingabe frei'
        )
    );
    $assert(
        !$form->official_message_distribution_readonly(),
        estab_dv_requirement(
            'NV-19-VERTEILER-AUSGANG',
            'Der Verteiler ist im Vordruck "' . $task . '" gesperrt'
        )
    );
    ob_start();
    $form->official_message_distribution();
    $markup = (string) ob_get_clean();
    $assert(
        str_contains(
            $markup,
            'name="16_23" value="16_23_bl" type="checkbox"'
        ),
        estab_dv_requirement(
            'NV-19-VERTEILER-AUSGANG',
            'Der Vordruck "' . $task . '" zeigt für eine besetzte '
                . 'Verteilerposition kein ankreuzbares Kästchen'
        )
    );
    $assert(
        str_contains(
            $markup,
            'aria-label="S3 als Empfänger auswählen"'
        ),
        estab_dv_requirement(
            'NV-19-VERTEILER-AUSGANG',
            'Das Verteilerkästchen im Vordruck "' . $task . '" trägt '
                . 'keine Beschriftung, die seine Funktion benennt'
        )
    );
    $assert(
        !str_contains($markup, 'estab-official-copy-indicator'),
        estab_dv_requirement(
            'NV-19-VERTEILER-AUSGANG',
            'Der Vordruck "' . $task . '" zeigt den Verteiler nur als '
                . 'schreibgeschützte Anzeige'
        )
    );
}

// The formal outgoing review is not a routing stage; it stays read-only.
$review = (new ReflectionClass(nachrichten4fach::class))
    ->newInstanceWithoutConstructor();
$review->task = 'Stab_sichten';
$review->formdata = ['04_richtung' => 'A', '16_empf' => 'S2_rt,S1_gn,'];
$review->empfarray = $matrix;
$review->feldbgcolor();
$review->get_access_by_task();
$assert(
    $review->official_message_distribution_readonly(),
    'The formal outgoing review may not reroute the author\'s distribution'
);

// The browser only ever submits matrix coordinates. Both mandatory copies are
// added by the server and survive a request that selects nothing at all.
$mandatory = ['S2_rt', 'S1_gn'];
$withoutChoice = estab_workflow_distribution_tokens([], $matrix, $mandatory);
$assert(
    $withoutChoice === 'S2_rt,S1_gn,',
    estab_dv_requirement(
        'NV-19-VERTEILER-AUSGANG',
        'Ohne Auswahl des Verfassers entsteht nicht der vorgeschriebene '
            . 'Verteiler, sondern "' . $withoutChoice . '"'
    )
);
$withChoice = estab_workflow_distribution_tokens(
    ['16_23' => '16_23_bl'],
    $matrix,
    $mandatory
);
$assert(
    $withChoice === 'S2_rt,S1_gn,S3_bl,',
    estab_dv_requirement(
        'NV-19-VERTEILER-AUSGANG',
        'Ein vom Verfasser angekreuzter Empfänger erscheint nicht als blaue '
            . 'Durchschrift, sondern als "' . $withChoice . '"'
    )
);
$assert(
    estab_workflow_distribution_has_processor($withChoice)
        && !estab_workflow_distribution_has_processor($withoutChoice),
    estab_dv_requirement(
        'NV-19-VERTEILER-AUSGANG',
        'Die blaue Durchschrift des Verfassers zählt nicht als benannter '
            . 'Bearbeiter'
    )
);

// The author may add recipients, never remove the two mandatory copies or
// name a function directly.
foreach ([
    ['16_empf' => 'S3_bl,'],
    ['16_empf_sonst_23' => 'S3'],
    ['16_gncopy' => '16_23_gn'],
    ['16_23' => '16_23_rt'],
    ['16_23' => '16_23_gn'],
] as $forged) {
    $refused = false;
    try {
        estab_workflow_distribution_tokens($forged, $matrix, $mandatory);
    } catch (InvalidArgumentException) {
        $refused = true;
    }
    $assert(
        $refused,
        estab_dv_requirement(
            'NV-19-VERTEILER-AUSGANG',
            'Der Browser darf den Verteiler mit "'
                . (string) array_key_first($forged) . '" selbst bestimmen'
        )
    );
}
$unstaffed = false;
try {
    estab_workflow_distribution_tokens(
        ['16_54' => '16_54_bl'],
        $matrix,
        $mandatory
    );
} catch (InvalidArgumentException) {
    $unstaffed = true;
}
$assert(
    $unstaffed,
    estab_dv_requirement(
        'NV-19-VERTEILER-AUSGANG',
        'Eine unbesetzte Verteilerposition lässt sich als Empfänger '
            . 'ankreuzen'
    )
);

// The stale-matrix guard only has to run when coordinates are submitted.
$assert(
    estab_workflow_distribution_has_selection(['16_23' => '16_23_bl'])
        && !estab_workflow_distribution_has_selection([
            'task' => 'Stab_schreiben',
            '16_empf' => '',
            '16_23' => '',
        ]),
    'The recipient-selection probe does not recognise submitted coordinates'
);

// Feld 19 stays optional for the author: a command post that does not staff
// every position still has to be able to send its message.
$outgoingComplete = static function (string $task, string $distribution): bool {
    $validator = new vali_data_form([
        'task' => $task,
        '04_richtung' => 'A',
        '16_empf' => $distribution,
    ]);
    $validator->checkallfields();
    foreach ([
        '09_vorrangstufe', '10_anschrift', '11_rufnummer', '12_betreff',
        '12_inhalt', '12_abfzeit', '13_abseinheit', '14_zeichen',
        '14_funktion',
    ] as $field) {
        $validator->validate[$field] = true;
    }
    // The legacy gate includes two runtime configuration files by a path
    // relative to the working directory. Running from a directory where they
    // resolve would open a database connection, so the gate is exercised
    // where they cannot resolve and only their include warnings are silenced.
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
foreach (['Stab_schreiben', 'Stab_korrigieren'] as $task) {
    $assert(
        $outgoingComplete($task, 'S2_rt,S1_gn,'),
        estab_dv_requirement(
            'NV-19-VERTEILER-AUSGANG',
            'Der Vordruck "' . $task . '" lässt sich ohne zusätzlich '
                . 'angekreuzten Empfänger nicht absenden'
        )
    );
    $assert(
        $outgoingComplete($task, 'S2_rt,S1_gn,S3_bl,'),
        estab_dv_requirement(
            'NV-19-VERTEILER-AUSGANG',
            'Der Vordruck "' . $task . '" weist einen vom Verfasser '
                . 'angekreuzten Empfänger zurück'
        )
    );
}

// The save boundary writes the author's distribution instead of a fixed list.
$handler = file_get_contents($root . '/4fach/data_hndl.php');
$assert(is_string($handler), 'The message handler could not be read');
$assert(
    !str_contains(
        $handler,
        '$redcopy2."_rt,".$data ["14_funktion"]."_gn"'
    ),
    estab_dv_requirement(
        'NV-19-VERTEILER-AUSGANG',
        'Die Speicherung verdrahtet den Verteiler der ausgehenden Nachricht '
            . 'weiterhin fest'
    )
);
$assert(
    str_contains(
        $handler,
        '$authorDistribution = estab_workflow_distribution_tokens ('
    )
    && str_contains(
        $handler,
        'estab_workflow_distribution_has_selection ($browserData)'
    ),
    estab_dv_requirement(
        'NV-19-VERTEILER-AUSGANG',
        'Die Speicherung löst die Verteilerauswahl des Verfassers nicht über '
            . 'die serverseitige Empfängermatrix auf'
    )
);
$writeCase = strrpos($handler, 'case "Stab_schreiben":');
$assert(is_int($writeCase), 'The outgoing write stage is gone from the handler');
$insert = strpos($handler, 'estab_message_insert_numbered (', $writeCase);
$written = strpos($handler, '$data ["16_empf"] = $authorDistribution;', $writeCase);
$assert(
    is_int($insert) && is_int($written) && $written < $insert,
    estab_dv_requirement(
        'NV-19-VERTEILER-AUSGANG',
        'Die neue Ausgangsnachricht wird gespeichert, ohne den Verteiler des '
            . 'Verfassers zu übernehmen'
    )
);
$correctionCase = strpos($handler, 'case "Stab_korrigieren":', $writeCase);
$assert(
    is_int($correctionCase),
    'The outgoing correction stage is gone from the handler'
);
$resubmit = strpos(
    $handler,
    'estab_message_resubmit_returned_outgoing (',
    $correctionCase
);
$corrected = strpos(
    $handler,
    '"16_empf" => $authorDistribution,',
    $correctionCase
);
$assert(
    is_int($resubmit) && is_int($corrected) && $corrected > $resubmit,
    estab_dv_requirement(
        'NV-19-VERTEILER-AUSGANG',
        'Die Korrektur einer zurückgegebenen Ausgangsnachricht schreibt den '
            . 'Verteiler des Verfassers nicht fort'
    )
);

// Switching the draft into the dedicated conversation-note stage keeps what
// the author already ticked.
$controller = file_get_contents($root . '/4fach/mainindex.php');
$assert(is_string($controller), 'The message controller could not be read');
$assert(
    !str_contains($controller, '$formdata ["16_empf"] = $redcopy2."_rt,".'),
    estab_dv_requirement(
        'NV-19-VERTEILER-AUSGANG',
        'Der Wechsel in die Gesprächsnotiz verwirft den bereits '
            . 'angekreuzten Verteiler'
    )
);

printf(
    "Outgoing message recipient: OK (%d assertions)\n",
    $assertions
);
