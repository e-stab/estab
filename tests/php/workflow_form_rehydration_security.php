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

$authoritative = [
    '00_lfd' => '71',
    '01_medium' => 'Fu',
    '01_datum' => '2026-07-31 08:01:00',
    '01_zeichen' => 'aw0001',
    '02_zeit' => '2026-07-31 08:03:00',
    '02_zeichen' => 'ld0001',
    '03_datum' => null,
    '03_zeichen' => '',
    '04_nummer' => '19',
    '04_richtung' => 'A',
    '05_gegenstelle' => 'Florian Gegenstelle',
    '06_befweg' => 'TMO-Gruppe 112',
    '06_befwegausw' => 'Fu',
    'estab_fernmeldeplan_eintrag_id' => '44',
    '07_durchspruch' => 'S',
    '08_befhinweis' => 'Bestandshinweis',
    '08_befhinwausw' => 'Fu',
    '09_vorrangstufe' => '',
    '10_anschrift' => 'An Einsatzabschnitt Nord',
    '11_rufnummer' => '0241 12345',
    '11_gesprnotiz' => 'f',
    '12_anhang' => 'lagebild.pdf',
    '12_betreff' => 'Lageänderung',
    '12_inhalt' => 'Autoritativer Nachrichtentext',
    '12_abfzeit' => '2026-07-31 07:59:00',
    '13_abseinheit' => 'Führungsstelle Nord',
    '14_zeichen' => 's20001',
    '14_funktion' => 'S2',
    '15_quitdatum' => '2026-07-31 08:02:00',
    '15_quitzeichen' => 'si0001',
    '16_empf' => 'S2_bl,LdF_rt,',
    '17_vermerke' => 'Unveränderlicher Rückgabegrund',
    'x00_status' => '10',
    'x01_abschluss' => 'f',
    'x02_sperre' => 'f',
    'x03_sperruser' => '',
];

$forgedEvidence = [
    '00_lfd' => '999',
    '01_medium' => 'Fe',
    '01_datum' => 'FORGED RECEIPT',
    '01_zeichen' => 'bad001',
    '02_zeit' => 'FORGED ACCEPTANCE',
    '02_zeichen' => 'bad002',
    '03_datum' => 'FORGED TRANSPORT',
    '03_zeichen' => 'bad003',
    '04_nummer' => '999',
    '04_richtung' => 'E',
    '05_gegenstelle' => 'Manipulierte Gegenstelle',
    '06_befweg' => 'Manipulierter Weg',
    '06_befwegausw' => 'Me',
    'estab_fernmeldeplan_eintrag_id' => '999',
    '13_abseinheit' => 'Manipulierter Absender',
    '14_zeichen' => 'bad004',
    '14_funktion' => 'Fremdfunktion',
    '15_quitdatum' => 'FORGED REVIEW',
    '15_quitzeichen' => 'bad005',
    '16_empf' => 'alle_gn,',
    '17_vermerke' => 'Manipulierter Rückgabegrund',
    'x00_status' => '8',
    'x01_abschluss' => 't',
    'x02_sperre' => 't',
    'x03_sperruser' => 'bad006',
];

$correction = estab_rehydrate_authoritative_message_form(
    $authoritative,
    array_replace($forgedEvidence, [
        '07_durchspruch' => 'D',
        '08_befhinweis' => 'Korrigierter Hinweis',
        '08_befhinwausw' => 'Fe',
        '09_vorrangstufe' => 'sss',
        '10_anschrift' => 'Korrigierte Anschrift',
        '11_rufnummer' => '+49 241 555',
        '12_anhang' => 'freigegebener-entwurf.pdf',
        '12_betreff' => 'Korrigierter Betreff',
        '12_inhalt' => 'Korrigierter Nachrichtentext',
        '12_abfzeit' => '310815Jul2026',
        '11_gesprnotiz' => 't',
        'estab_route_error' => 'Validierungsfehler',
    ]),
    'Stab_korrigieren',
    [
        '11_gesprnotiz' => 'f',
        '13_abseinheit' => 'Führungsstelle Einsatz',
        '14_zeichen' => 's20002',
        '14_funktion' => 'S2',
    ]
);
$assert(
    $correction['00_lfd'] === '71'
        && $correction['task'] === 'Stab_korrigieren'
        && $correction['01_datum'] === $authoritative['01_datum']
        && $correction['01_zeichen'] === 'aw0001'
        && $correction['02_zeit'] === $authoritative['02_zeit']
        && $correction['02_zeichen'] === 'ld0001'
        && $correction['15_quitdatum'] === $authoritative['15_quitdatum']
        && $correction['15_quitzeichen'] === 'si0001'
        && $correction['16_empf'] === $authoritative['16_empf']
        && $correction['17_vermerke'] === $authoritative['17_vermerke'],
    'staff correction reflected forged or empty workflow evidence'
);
$assert(
    $correction['12_inhalt'] === 'Korrigierter Nachrichtentext'
        && $correction['12_betreff'] === 'Korrigierter Betreff'
        && $correction['10_anschrift'] === 'Korrigierte Anschrift'
        && $correction['11_gesprnotiz'] === 'f'
        && $correction['13_abseinheit'] === 'Führungsstelle Einsatz'
        && $correction['14_zeichen'] === 's20002'
        && $correction['14_funktion'] === 'S2',
    'staff correction lost editable input or server-owned author identity'
);
$assert(
    $correction['08_befhinweis'] === $authoritative['08_befhinweis']
        && $correction['08_befhinwausw']
            === $authoritative['08_befhinwausw'],
    'staff correction replaced historical transport-hint evidence'
);

$newRecordFields = estab_message_new_record_fields([
    '07_durchspruch' => 'D',
    '08_befhinweis' => 'Manipulierter neuer Hinweis',
    '08_befhinwausw' => 'Fe',
]);
$assert(
    $newRecordFields['07_durchspruch'] === 'D'
        && $newRecordFields['08_befhinweis'] === ''
        && $newRecordFields['08_befhinwausw'] === '',
    'new-record boundary accepted retired transport-hint browser fields'
);

$existingRecordFields = estab_message_existing_record_fields([
    '07_durchspruch' => 'S',
    '08_befhinweis' => '',
    '08_befhinwausw' => 'Me',
]);
$assert(
    $existingRecordFields === ['07_durchspruch' => 'S'],
    'existing-record update could clear or replace transport-hint evidence'
);

$followup = estab_message_followup_new_record([
    '00_lfd' => '71',
    'estab_ttb_lfd' => '19',
    'msglfd' => '71',
    '08_befhinweis' => 'Historischer Hinweis',
    '08_befhinwausw' => 'Fu',
    '12_inhalt' => 'Übernommener Inhalt',
]);
$assert(
    $followup['00_lfd'] === ''
        && !array_key_exists('estab_ttb_lfd', $followup)
        && !array_key_exists('msglfd', $followup)
        && $followup['08_befhinweis'] === ''
        && $followup['08_befhinwausw'] === ''
        && $followup['12_inhalt'] === 'Übernommener Inhalt',
    'reply or forward draft inherited retired transport-hint values'
);

$leadOutgoing = estab_rehydrate_authoritative_message_form(
    array_replace($authoritative, ['x00_status' => '1']),
    array_replace($forgedEvidence, [
        '01_medium' => 'Me',
        '02_zeit' => '310830Jul2026',
        '05_gegenstelle' => 'Florian Ziel Neu',
        '06_befweg' => 'Melder über Bereitstellungsraum',
        'fernmeldeplan_eintrag_id' => '52',
        'estab_route_error' => 'Plan ist nicht mehr gültig',
    ]),
    'LdF-Ausgang',
    ['02_zeichen' => 'ld0002']
);
$assert(
    $leadOutgoing['02_zeit'] === '310830Jul2026'
        && $leadOutgoing['02_zeichen'] === 'ld0002'
        && $leadOutgoing['05_gegenstelle'] === 'Florian Ziel Neu'
        && $leadOutgoing['fernmeldeplan_eintrag_id'] === '52'
        // Feld 1 und Feld 6 sind die Disposition dieses Arbeitsschritts und
        // überstehen deshalb eine abgewiesene Eingabe.
        && $leadOutgoing['01_medium'] === 'Me'
        && $leadOutgoing['06_befweg'] === 'Melder über Bereitstellungsraum'
        // Feld 7 bleibt der Wunsch des Verfassers.
        && $leadOutgoing['06_befwegausw'] === $authoritative['06_befwegausw']
        && $leadOutgoing['12_inhalt'] === $authoritative['12_inhalt']
        && $leadOutgoing['15_quitzeichen'] === 'si0001'
        && $leadOutgoing['17_vermerke'] === $authoritative['17_vermerke'],
    'LdF outgoing error form lost authoritative evidence or editable disposition'
);

$telecommunications = estab_rehydrate_authoritative_message_form(
    array_replace($authoritative, ['x00_status' => '2']),
    array_replace($forgedEvidence, [
        '03_datum' => '310842Jul2026',
        'transportweg_bestaetigt' => '1',
        'transport_rueckgabegrund' => 'Gegenstelle nicht erreichbar',
        'estab_route_error' => 'Beförderung noch nicht bestätigt',
    ]),
    'FM-Ausgang',
    ['03_zeichen' => 'aw0002']
);
$assert(
    $telecommunications['03_datum'] === '310842Jul2026'
        && $telecommunications['03_zeichen'] === 'aw0002'
        && $telecommunications['transportweg_bestaetigt'] === '1'
        && $telecommunications['transport_rueckgabegrund']
            === 'Gegenstelle nicht erreichbar'
        && $telecommunications['02_zeit'] === $authoritative['02_zeit']
        && $telecommunications['02_zeichen'] === 'ld0001'
        && $telecommunications['06_befweg'] === $authoritative['06_befweg']
        && $telecommunications['06_befwegausw'] === 'Fu'
        && $telecommunications['15_quitzeichen'] === 'si0001'
        && $telecommunications['16_empf'] === $authoritative['16_empf'],
    'A/W outgoing error form lost the signed LdF/Si route evidence'
);

$leadIncoming = estab_rehydrate_authoritative_message_form(
    array_replace($authoritative, [
        '04_richtung' => 'E',
        '02_zeit' => null,
        '02_zeichen' => '',
        '15_quitdatum' => null,
        '15_quitzeichen' => '',
    ]),
    array_replace($forgedEvidence, [
        '01_medium' => 'Me',
        '02_zeit' => '310825Jul2026',
        '13_abseinheit' => 'THW Ortsverband Nord',
        'incoming_transport_confirmed' => '1',
        'incoming_transport_correction_reason' => 'Kurier bestätigt',
    ]),
    'LdF-Eingang',
    ['02_zeichen' => 'ld0002']
);
$assert(
    $leadIncoming['01_medium'] === 'Me'
        && $leadIncoming['02_zeit'] === '310825Jul2026'
        && $leadIncoming['02_zeichen'] === 'ld0002'
        && $leadIncoming['13_abseinheit'] === 'THW Ortsverband Nord'
        && $leadIncoming['01_datum'] === $authoritative['01_datum']
        && $leadIncoming['01_zeichen'] === 'aw0001'
        && $leadIncoming['12_inhalt'] === $authoritative['12_inhalt'],
    'LdF incoming rehydration no longer protects A/W receipt evidence'
);

$nonString = estab_rehydrate_authoritative_message_form(
    $authoritative,
    ['00_lfd' => '71', '03_datum' => ['forged']],
    'FM-Ausgang',
    ['03_zeichen' => 'aw0002']
);
$assert(
    $nonString['03_datum'] === '' && $nonString['03_zeichen'] === 'aw0002',
    'non-scalar browser input survived workflow rehydration'
);

try {
    estab_rehydrate_authoritative_message_form(
        $authoritative,
        [],
        'Unbekannt'
    );
    $assert(false, 'unknown workflow accepted by rehydration whitelist');
} catch (InvalidArgumentException) {
    $assert(true, 'unknown workflow rejected by rehydration whitelist');
}

$controller = file_get_contents($root . '/4fach/data_hndl.php');
$formController = file_get_contents($root . '/4fach/4fachform.php');
$repository = file_get_contents($root . '/app/message_repository.php');
if (
    !is_string($controller)
    || !is_string($formController)
    || !is_string($repository)
) {
    throw new RuntimeException('Could not read message workflow sources');
}
$assert(
    substr_count($controller, 'estab_rehydrate_staff_correction_form (') >= 4
        && substr_count($controller, 'estab_rehydrate_locked_operator_form (') >= 6
        && str_contains(
            $controller,
            'estab_render_message_stage_conflict ("Die Fernmelder-Sperre")'
        ),
    'validation/conflict branches do not all use authoritative rehydration'
);
$assert(
    substr_count(
        $controller,
        'estab_message_new_record_fields (array ('
    ) === 3
        && str_contains(
            $repository,
            '$draft = estab_message_new_record_fields($draft);'
        )
        && str_contains(
            $repository,
            '$fields = estab_message_new_record_fields($fields);'
        )
        && substr_count(
            $repository,
            '$fields = estab_message_new_record_fields($fields);'
        ) === 2
        && str_contains(
            $repository,
            'estab_message_existing_record_fields($fields)'
        ),
    'not every current new-message path retires legacy transport hints'
);
$assert(
    str_contains(
        $formController,
        '$this->bg [8] = $this->feldbg [8]["i"];'
    )
        && str_contains($formController, '$this->feld [8] = false;'),
    'a current message task can still mark legacy transport hints editable'
);
$conversationStart = strrpos($controller, 'case "Stab_gesprnoti":');
$conversationValidation = $conversationStart === false
    ? false
    : strpos($controller, 'if (validate){', $conversationStart);
$conversationDistribution = $conversationStart === false
    ? false
    : strpos(
        $controller,
        '$data ["16_empf"] = estab_workflow_distribution_tokens (',
        $conversationStart
    );
$conversationEnd = $conversationStart === false
    ? false
    : strpos($controller, 'case "LdF-Eingang":', $conversationStart);
$conversationBlock = (
    is_int($conversationStart)
    && is_int($conversationEnd)
    && $conversationEnd > $conversationStart
) ? substr(
    $controller,
    $conversationStart,
    $conversationEnd - $conversationStart
) : '';
$assert(
    $conversationStart !== false
        && $conversationValidation !== false
        && $conversationDistribution !== false
        && $conversationDistribution < $conversationValidation
        && str_contains(
            substr(
                $controller,
                $conversationDistribution,
                $conversationValidation - $conversationDistribution
            ),
            '$browserData'
    ),
    'Conversation-note recipient coordinates are not restored before validation errors'
);
$conversationRevision = strpos(
    $conversationBlock,
    'estab_workflow_require_recipient_matrix_revision ('
);
$conversationPreValidationDistribution = strpos(
    $conversationBlock,
    '$data ["16_empf"] = estab_workflow_distribution_tokens ('
);
$conversationValidationStart = strpos(
    $conversationBlock,
    'if (validate){'
);
$conversationFinalDistribution = is_int($conversationValidationStart)
    ? strpos(
        $conversationBlock,
        '$data ["16_empf"] = estab_workflow_distribution_tokens (',
        $conversationValidationStart
    )
    : false;
$requiredConversationDistribution =
    'array ($redcopy2."_rt", $sessionFunction."_gn")';
$assert(
    $conversationBlock !== ''
        && is_int($conversationRevision)
        && is_int($conversationPreValidationDistribution)
        && is_int($conversationValidationStart)
        && is_int($conversationFinalDistribution)
        && $conversationRevision < $conversationPreValidationDistribution
        && $conversationPreValidationDistribution
            < $conversationValidationStart
        && $conversationValidationStart < $conversationFinalDistribution
        && substr_count(
            $conversationBlock,
            $requiredConversationDistribution
        ) === 2,
    'conversation-note rehydration and final save do not share the mandatory red and author-green distribution'
);
$preValidationBody = (
    is_int($conversationPreValidationDistribution)
    && is_int($conversationValidationStart)
) ? substr(
    $conversationBlock,
    $conversationPreValidationDistribution,
    $conversationValidationStart - $conversationPreValidationDistribution
) : '';
$assert(
    str_contains($preValidationBody, '$browserData')
        && str_contains(
            $preValidationBody,
            $requiredConversationDistribution
        )
        && str_contains(
            $conversationBlock,
            '$form = new nachrichten4fach ($data, $data["task"], $vali->validate);'
        ),
    'conversation-note validation failure cannot re-render the selected recipients plus mandatory copies'
);
$retiredGreenGuard = strpos(
    $controller,
    'if (array_key_exists ("16_gncopy", $browserData)) {'
);
$saveDefaults = strpos(
    $controller,
    '$data = array_replace (array_fill_keys (array ('
);
$assert(
    is_int($retiredGreenGuard)
        && is_int($saveDefaults)
        && $retiredGreenGuard < $saveDefaults
        && str_contains(
            substr(
                $controller,
                $retiredGreenGuard,
                $saveDefaults - $retiredGreenGuard
            ),
            'estab_workflow_forbid ();'
        )
        && substr_count($controller, '"16_gncopy"') === 1
        && !str_contains($conversationBlock, '16_gncopy'),
    'the save boundary accepts or reconstructs the retired green-copy field'
);
$assert(
    str_contains(
        $controller,
        '"11_gesprnotiz" => (string) ($message ["11_gesprnotiz"] ?? "f")'
    )
        && !str_contains(
            $controller,
            '"11_gesprnotiz" => "f",' . "\n"
                . '          "12_anhang"'
        ),
    'a returned conversation note can lose its immutable type on resubmission'
);

printf(
    "Workflow form rehydration security: OK (%d assertions)\n",
    $assertions
);
