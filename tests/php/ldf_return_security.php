<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$previousDirectory = getcwd();
if (!chdir($root . '/4fach')) {
    throw new RuntimeException('Could not enter the message controller directory');
}
try {
    require_once $root . '/4fach/data_hndl.php';
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
$sourceSection = static function (
    string $source,
    string $start,
    string $end
): string {
    $startOffset = strpos($source, $start);
    if (!is_int($startOffset)) {
        throw new RuntimeException('Missing source boundary: ' . $start);
    }
    $endOffset = strpos($source, $end, $startOffset + strlen($start));
    if (!is_int($endOffset) || $endOffset <= $startOffset) {
        throw new RuntimeException('Missing source boundary: ' . $end);
    }

    return substr($source, $startOffset, $endOffset - $startOffset);
};

$lead = [
    'benutzer' => 'Leitung Fernmeldebetrieb',
    'kuerzel' => 'ldf001',
    'funktion' => 'LdF',
    'rolle' => 'Fernmelder',
];
$foreignIdentities = [
    [
        'benutzer' => 'Fernmelder',
        'kuerzel' => 'fm0001',
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
    ],
    [
        'benutzer' => 'Sichter',
        'kuerzel' => 'si0001',
        'funktion' => 'Si',
        'rolle' => 'Stab',
    ],
    [
        'benutzer' => 'Sachgebiet',
        'kuerzel' => 's20001',
        'funktion' => 'S2',
        'rolle' => 'Stab',
    ],
];

// Bind the executable route tests explicitly to STRICT. LOOSE intentionally
// relaxes fixed write-role checks for the incident and has separate contracts.
$permissionContextKey = ESTAB_PERMISSION_CONTEXT_KEY;
$hadPermissionContext = array_key_exists($permissionContextKey, $GLOBALS);
$previousPermissionContext = $GLOBALS[$permissionContextKey] ?? null;
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 17,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
    'revision' => 4,
]);

$csrf = str_repeat('a', 64);
$validReturnPost = [
    'csrf_token' => $csrf,
    'task' => 'LdF-Ausgang',
    '00_lfd' => '71',
    'ldf_zurueckweisen_x' => '1',
    'ldf_rueckgabegrund' => 'Rufname der Gegenstelle ist nicht eindeutig.',
];
$assert(
    estab_workflow_action_keys_allowed([
        'ldf_zurueckweisen_x' => '1',
        'ldf_zurueckweisen_y' => '2',
    ])
        && estab_workflow_existing_operational_post($validReturnPost)
        && estab_workflow_existing_operational_post(
            $validReturnPost + ['ldf_zurueckweisen_y' => '2']
        ),
    'LdF return coordinates are absent from the exact operational action allowlist'
);
$assert(
    !estab_workflow_existing_operational_post(
        array_diff_key($validReturnPost, ['ldf_zurueckweisen_x' => true])
            + ['ldf_zurueckweisen_y' => '2']
    )
        && !estab_workflow_existing_operational_post(
            $validReturnPost + ['absenden_x' => '1']
        )
        && !estab_workflow_action_keys_allowed([
            'ldf_zurueckweisen_extra_x' => '1',
        ]),
    'Incomplete, combined or invented LdF return actions passed the allowlist'
);
$assert(
    estab_workflow_route_allowed($lead, 'POST', $validReturnPost)
        && !estab_workflow_route_allowed($lead, 'GET', $validReturnPost)
        && !estab_workflow_route_allowed(
            $lead,
            'POST',
            array_replace($validReturnPost, ['task' => 'LdF-Eingang'])
        ),
    'LdF return is not bound to POST and the outgoing LdF task'
);
foreach ($foreignIdentities as $foreignIdentity) {
    $assert(
        !estab_workflow_route_allowed(
            $foreignIdentity,
            'POST',
            $validReturnPost
        ),
        'STRICT admitted a foreign workflow role to the LdF return'
    );
}

$assert(
    estab_workflow_route_allowed(
        $lead,
        'POST',
        array_replace($validReturnPost, [
            'ldf_rueckgabegrund' => str_repeat('x', 2000),
        ])
    ),
    'The documented 2000-character LdF return-reason boundary was rejected'
);
foreach ([
    str_repeat('x', 2001),
    "ungueltig\0",
    "ungueltig\xC3\x28",
    ['kein Text'],
] as $unsafeReason) {
    $assert(
        !estab_workflow_route_allowed(
            $lead,
            'POST',
            array_replace($validReturnPost, [
                'ldf_rueckgabegrund' => $unsafeReason,
            ])
        ),
        'Malformed or overlong LdF return reason passed the request boundary'
    );
}

if ($hadPermissionContext) {
    $GLOBALS[$permissionContextKey] = $previousPermissionContext;
} else {
    unset($GLOBALS[$permissionContextKey]);
}

// Invalid reasons are rejected before any database access. A local stand-in
// keeps this executable even in the dependency-free PHP lint container.
if (!class_exists('mysqli')) {
    class mysqli
    {
    }
}
$validationConnection = new mysqli();
foreach ([
    null,
    ['kein Text'],
    '',
    " \t\r\n ",
    str_repeat('x', 2001),
    "ungueltig\x01",
    "ungueltig\xC3\x28",
] as $invalidRepositoryReason) {
    try {
        estab_message_ldf_return_outgoing(
            $validationConnection,
            'nachrichten',
            71,
            $lead,
            $invalidRepositoryReason,
            17
        );
        $assert(false, 'Repository accepted a missing or unsafe LdF return reason');
    } catch (EstabDvInputException $exception) {
        $assert(
            str_contains($exception->getMessage(), 'Grund'),
            'Repository returned no useful validation message for the LdF reason'
        );
    }
}

$authoritative = [
    '00_lfd' => '71',
    '04_richtung' => 'A',
    '05_gegenstelle' => 'Florian Alt',
    '12_inhalt' => 'Unveränderlicher Nachrichtentext',
    '15_quitdatum' => '2026-08-02 10:00:00',
    '15_quitzeichen' => 'si0001',
    '17_vermerke' => 'Vorheriger Prüfvermerk',
    'x00_status' => '1',
    'x02_sperre' => 't',
    'x03_sperruser' => 'ldf001',
];
$submittedReason = 'Rufname und Anschrift müssen korrigiert werden.';
$rehydrated = estab_rehydrate_authoritative_message_form(
    $authoritative,
    [
        '00_lfd' => '999',
        '02_zeit' => '021025Aug2026',
        '02_zeichen' => 'forged',
        '05_gegenstelle' => 'Florian Neu',
        'fernmeldeplan_eintrag_id' => '52',
        'ldf_rueckgabegrund' => $submittedReason,
        'estab_route_error' => 'Für die Rückgabe ist ein Grund erforderlich.',
        '12_inhalt' => 'Manipulierter Nachrichtentext',
        '17_vermerke' => 'Manipulierter Prüfvermerk',
        'x00_status' => '10',
    ],
    'LdF-Ausgang',
    ['02_zeichen' => 'ldf001']
);
$assert(
    $rehydrated['00_lfd'] === '71'
        && $rehydrated['task'] === 'LdF-Ausgang'
        && $rehydrated['02_zeit'] === '021025Aug2026'
        && $rehydrated['02_zeichen'] === 'ldf001'
        && $rehydrated['05_gegenstelle'] === 'Florian Neu'
        && $rehydrated['fernmeldeplan_eintrag_id'] === '52'
        && $rehydrated['ldf_rueckgabegrund'] === $submittedReason
        && $rehydrated['estab_route_error']
            === 'Für die Rückgabe ist ein Grund erforderlich.',
    'LdF return validation response lost editable input, reason or server actor'
);
$assert(
    $rehydrated['12_inhalt'] === $authoritative['12_inhalt']
        && $rehydrated['15_quitzeichen'] === 'si0001'
        && $rehydrated['17_vermerke'] === $authoritative['17_vermerke']
        && $rehydrated['x00_status'] === '1'
        && $rehydrated['x02_sperre'] === 't'
        && $rehydrated['x03_sperruser'] === 'ldf001',
    'LdF return rehydration reflected forged immutable workflow evidence'
);
$nonStringRehydrated = estab_rehydrate_authoritative_message_form(
    $authoritative,
    ['ldf_rueckgabegrund' => ['forged']],
    'LdF-Ausgang',
    ['02_zeichen' => 'ldf001']
);
$assert(
    $nonStringRehydrated['ldf_rueckgabegrund'] === ''
        && $nonStringRehydrated['17_vermerke'] === 'Vorheriger Prüfvermerk',
    'Non-scalar return input survived authoritative LdF rehydration'
);

$workflowSource = file_get_contents($root . '/app/workflow.php');
$repositorySource = file_get_contents($root . '/app/message_repository.php');
$handlerSource = file_get_contents($root . '/4fach/data_hndl.php');
$formSource = file_get_contents($root . '/4fach/official_message_form.php');
$formControllerSource = file_get_contents($root . '/4fach/4fachform.php');
$mainControllerSource = file_get_contents($root . '/4fach/mainindex.php');
foreach ([
    $workflowSource,
    $repositorySource,
    $handlerSource,
    $formSource,
    $formControllerSource,
    $mainControllerSource,
] as $source) {
    $assert(is_string($source), 'Could not read an LdF return workflow source');
}

$repositoryReturn = $sourceSection(
    $repositorySource,
    'function estab_message_ldf_return_outgoing(',
    'function estab_message_release_operator_stage_lock('
);
$selectPosition = strpos($repositoryReturn, "'SELECT `15_quitdatum`");
$updatePosition = strpos($repositoryReturn, "'UPDATE '");
$evidencePosition = strpos(
    $repositoryReturn,
    'estab_message_append_transition_evidence('
);
$successPosition = strrpos($repositoryReturn, 'return true;');
$assert(
    str_contains($repositoryReturn, '$reason = trim($reason);')
        && str_contains($repositoryReturn, '$reason === \'\'')
        && str_contains($repositoryReturn, '$reasonLength > 2000')
        && str_contains($repositoryReturn, "preg_match('/\\p{C}/u'")
        && str_contains($repositoryReturn, 'throw new EstabDvInputException('),
    'Repository lacks mandatory, bounded and control-safe return-reason validation'
);
$assert(
    str_contains(
        $repositoryReturn,
        "estab_message_operator_stage_predicate('A', 1)"
    )
        && str_contains(
            $repositoryReturn,
            "estab_message_require_operator_stage_actor("
        )
        && str_contains($repositoryReturn, "'A',\n                1")
        && str_contains($repositoryReturn, 'estab_incident_with_active_write(')
        && str_contains($repositoryReturn, 'estab_message_transaction_incident_id('),
    'Repository return is not transactionally bound to outgoing LdF stage 1 and its incident'
);
$assert(
    is_int($selectPosition)
        && is_int($updatePosition)
        && is_int($evidencePosition)
        && is_int($successPosition)
        && $selectPosition < $updatePosition
        && $updatePosition < $evidencePosition
        && $evidencePosition < $successPosition
        && str_contains($repositoryReturn, ' FOR UPDATE')
        && substr_count($repositoryReturn, 'estab_message_append_transition_evidence(') === 1,
    'LdF return row mutation and immutable evidence are not ordered in one atomic write'
);
$assert(
    str_contains($repositoryReturn, '`17_vermerke` = ?')
        && str_contains($repositoryReturn, '`x00_status` = 10')
        && str_contains($repositoryReturn, "`x01_abschluss` = 'f'")
        && str_contains($repositoryReturn, "`x02_sperre` = 'f'")
        && str_contains($repositoryReturn, "`x03_sperruser` = ''")
        && str_contains($repositoryReturn, '`einsatz_id` = ?')
        && str_contains($repositoryReturn, "`x02_sperre` = 't'")
        && str_contains(
            $repositoryReturn,
            'BINARY `x03_sperruser` = BINARY ?'
        )
        && str_contains($repositoryReturn, '$updateStatement->affected_rows !== 1'),
    'LdF return update is not a conflict-safe 1-to-10 transition that releases its exact lock'
);
$assert(
    str_contains($repositoryReturn, "'event_type' => 'ldf_returned'")
        && str_contains($repositoryReturn, "'actor' => \$operationalActor")
        && str_contains($repositoryReturn, "'from_status' => 1")
        && str_contains($repositoryReturn, "'to_status' => 10")
        && str_contains($repositoryReturn, "'return_reason' => \$reason")
        && str_contains($repositoryReturn, "'returned_by' => \$operatorCode")
        && str_contains($repositoryReturn, "'reviewed_at'")
        && str_contains($repositoryReturn, "'reviewed_by'")
        && str_contains($repositoryReturn, "'previous_note'"),
    'Immutable LdF-return evidence omits transition, actor, reason or prior review state'
);

$handlerReturn = $sourceSection(
    $handlerSource,
    '$ldfReturnRequested = $ldfTask === "LdF-Ausgang"',
    'if ($ldfDirection === "E") {'
);
$assert(
    str_contains($handlerReturn, 'isset ($data ["ldf_zurueckweisen_x"])')
        && str_contains($handlerReturn, 'isset ($data ["ldf_zurueckweisen_y"])')
        && str_contains($handlerReturn, 'estab_message_ldf_return_outgoing (')
        && str_contains($handlerReturn, '$messageActor,')
        && str_contains($handlerReturn, '$data ["ldf_rueckgabegrund"],')
        && str_contains($handlerReturn, '$expectedIncidentId')
        && str_contains($handlerReturn, 'catch (EstabDvInputException $exception)')
        && str_contains($handlerReturn, 'http_response_code (422);')
        && str_contains($handlerReturn, 'estab_rehydrate_locked_operator_form (')
        && str_contains($handlerReturn, 'estab_render_ldf_stage_conflict ();')
        && str_contains($handlerReturn, 'break;'),
    'LdF handler does not safely dispatch, report and rehydrate the return transition'
);
$assert(
    str_contains($mainControllerSource, 'isset ($request ["ldf_zurueckweisen_x"])')
        && str_contains($mainControllerSource, 'isset ($request ["ldf_zurueckweisen_y"])')
        && str_contains($handlerSource, '"ldf_rueckgabegrund",'),
    'Active-incident admission or the known-input boundary omits the LdF return'
);

$returnFieldPosition = strpos($formSource, 'data-estab-ldf-return');
$returnFieldCondition = is_int($returnFieldPosition)
    ? strrpos(
        substr($formSource, 0, $returnFieldPosition),
        "if (\$this->task === 'LdF-Ausgang') {"
    )
    : false;
$assert(
    is_int($returnFieldPosition)
        && is_int($returnFieldCondition)
        && str_contains($formSource, 'name="ldf_rueckgabegrund" maxlength="2000"')
        && str_contains(
            $formSource,
            "\$this->safe_message_value('ldf_rueckgabegrund')"
        )
        && str_contains($formControllerSource, '"ldf_rueckgabegrund",'),
    'Outgoing LdF form lacks its bounded, escaped and rehydratable return-reason field'
);
$assert(
    substr_count($formSource, 'name="ldf_zurueckweisen_x"') === 1
        && str_contains($formSource, 'An Verfasser zurückgeben</button>')
        && str_contains($formSource, 'estab-button-danger')
        && str_contains($formSource, 'formnovalidate'),
    'Outgoing LdF form lacks one explicit non-destructive return action'
);

// Feld 20 sammelt die Vermerke des gesamten Laufwegs: Die Rückgabe an den
// Verfasser ergänzt den vorhandenen Sichtervermerk, statt ihn zu ersetzen.
require_once $root . '/app/dv_rules.php';

$assert(
    function_exists('estab_message_note_with_entry')
        && defined('ESTAB_MESSAGE_NOTE_MAX_LENGTH'),
    estab_dv_requirement(
        'NV-20-VERMERKE-ERHALT',
        'Für Feld 20 gibt es keine anfügende, begrenzte Schreiboperation'
    )
);

$previousNote = 'Vorheriger Prüfvermerk';
$returnMarker = 'Rückgabe an den Verfasser durch LdF ldf001';
$mergedNote = estab_message_note_with_entry(
    $previousNote,
    $returnMarker,
    $submittedReason,
    '02.08.2026 10:15'
);
$assert(
    str_starts_with($mergedNote, $previousNote . "\n")
        && str_contains($mergedNote, $submittedReason)
        && str_contains($mergedNote, $returnMarker)
        && str_contains($mergedNote, '02.08.2026 10:15'),
    estab_dv_requirement(
        'NV-20-VERMERKE-ERHALT',
        'Der Rückgabegrund überschreibt den vorhandenen Vermerk, statt ihn '
            . 'mit Zeitpunkt und Stelle darunter zu ergänzen'
    )
);

$secondReturnNote = estab_message_note_with_entry(
    $mergedNote,
    'Rückgabe an den Verfasser durch LdF ldf002',
    'Anschrift weiterhin unvollständig.',
    '02.08.2026 11:20'
);
$assert(
    str_starts_with($secondReturnNote, $mergedNote . "\n")
        && str_contains($secondReturnNote, $previousNote)
        && str_contains($secondReturnNote, $submittedReason)
        && substr_count($secondReturnNote, 'Rückgabe an den Verfasser') === 2,
    estab_dv_requirement(
        'NV-20-VERMERKE-ERHALT',
        'Eine zweite Rückgabe löscht die Vermerke der ersten'
    )
);

$firstNote = estab_message_note_with_entry(
    '',
    $returnMarker,
    $submittedReason,
    '02.08.2026 10:15'
);
$assert(
    !str_starts_with($firstNote, "\n")
        && str_contains($firstNote, $submittedReason),
    estab_dv_requirement(
        'NV-20-VERMERKE-ERHALT',
        'Der erste Vermerk eines Laufwegs beginnt mit einer leeren Zeile'
    )
);

$filledNote = str_repeat('a', ESTAB_MESSAGE_NOTE_MAX_LENGTH - 100);
$boundedNote = estab_message_note_with_entry(
    $filledNote,
    $returnMarker,
    str_repeat('b', 2000),
    '02.08.2026 10:15'
);
$assert(
    str_starts_with($boundedNote, $filledNote)
        && estab_auth_text_length($boundedNote)
            <= ESTAB_MESSAGE_NOTE_MAX_LENGTH
        && estab_auth_text_length($boundedNote)
            > estab_auth_text_length($filledNote),
    estab_dv_requirement(
        'NV-20-VERMERKE-ERHALT',
        'Die Längenbegrenzung von Feld 20 verkürzt die bereits '
            . 'eingetragenen Vermerke'
    )
);

$mergePosition = strpos($repositoryReturn, 'estab_message_note_with_entry(');
$assert(
    is_int($selectPosition)
        && is_int($mergePosition)
        && is_int($updatePosition)
        && $selectPosition < $mergePosition
        && $mergePosition < $updatePosition
        && str_contains($repositoryReturn, "\$message['17_vermerke'] ?? ''")
        && str_contains(
            $repositoryReturn,
            '[$mergedNote, $recordId, $incidentId],'
        )
        && !str_contains(
            $repositoryReturn,
            '[$reason, $recordId, $incidentId],'
        ),
    estab_dv_requirement(
        'NV-20-VERMERKE-ERHALT',
        'Die LdF-Rückgabe schreibt den Rückgabegrund roh in Feld 20, statt '
            . 'den unter FOR UPDATE gelesenen Vermerk zu ergänzen'
    )
);

printf("LdF return security: OK (%d assertions)\n", $assertions);
