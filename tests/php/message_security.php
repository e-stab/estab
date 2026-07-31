<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/message_repository.php';
require_once __DIR__ . '/../../app/workflow.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach (['1', '42', 7] as $validId) {
    $assert(estab_message_positive_id($validId) > 0, 'positive message ID rejected');
}
foreach (['', '0', '-1', '+1', '01', '1 OR 1=1', '1.0', [], null] as $invalidId) {
    try {
        estab_message_positive_id($invalidId);
        $assert(false, 'unsafe message ID accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'unsafe message ID rejected');
    }
}

$assert(estab_message_table('nv_nachrichten') === '`nv_nachrichten`', 'safe table rejected');
foreach (['x` WHERE 1', 'x.y', '', '1table'] as $invalidTable) {
    try {
        estab_message_table($invalidTable);
        $assert(false, 'unsafe table accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'unsafe table rejected');
    }
}
try {
    estab_message_fields(['12_inhalt` = NULL' => 'payload']);
    $assert(false, 'unsafe message column accepted');
} catch (InvalidArgumentException) {
    $assert(true, 'unsafe message column rejected');
}
$assert(
    estab_message_state_table('usr_', 'S1', 'st0001', 'read')
        === 'usr_s1_st0001_read',
    'read-state table name changed'
);
$assert(
    estab_message_state_table('usr_', 'S1', 'st0001', 'done')
        === 'usr__fkt_s1_erl',
    'done-state table name changed'
);
$assert(
    estab_message_counter_lock_name('estab', 'nv_nachrichten')
        === estab_message_counter_lock_name('estab', 'nv_nachrichten'),
    'message counter lock namespace is not deterministic'
);
$terminalFixture = [
    'einsatz_id' => '7',
    '00_lfd' => '42',
    '04_nummer' => '9',
    '04_richtung' => 'A',
    '06_befweg' => 'Kanal 404',
    '06_befwegausw' => 'Fu',
    'estab_fernmeldeplan_eintrag_id' => '12',
    '12_inhalt' => 'Unveränderlicher Inhalt',
    '16_empf' => 'S1_rt',
    '17_vermerke' => 'Formal geprüft',
    '20_master_katego' => '3',
    'x00_status' => '8',
    'x01_abschluss' => 't',
    'x02_sperre' => 'f',
    'x03_sperruser' => '',
    'x04_druck' => 't',
    'x05_druck_d' => '2026-07-23 13:00:00',
    '99_lstacc' => '2026-07-23 13:01:00',
];
$terminalSnapshot = estab_message_terminal_snapshot($terminalFixture);
$assert(
    ($terminalSnapshot['00_lfd'] ?? null) === 42
        && ($terminalSnapshot['estab_fernmeldeplan_eintrag_id'] ?? null) === 12
        && ($terminalSnapshot['12_inhalt'] ?? null) === 'Unveränderlicher Inhalt'
        && !array_key_exists('20_master_katego', $terminalSnapshot)
        && !array_key_exists('x02_sperre', $terminalSnapshot)
        && !array_key_exists('x04_druck', $terminalSnapshot)
        && !array_key_exists('99_lstacc', $terminalSnapshot),
    'terminal evidence snapshot includes mutable organisational/technical metadata'
);
$assert(
    estab_message_terminal_snapshot_sha256($terminalFixture)
        === estab_message_terminal_snapshot_sha256(
            array_replace($terminalFixture, [
                '20_master_katego' => '99',
                'x04_druck' => 'f',
                '99_lstacc' => '2030-01-01 00:00:00',
            ])
        )
        && estab_message_terminal_snapshot_sha256($terminalFixture)
            !== estab_message_terminal_snapshot_sha256(
                array_replace($terminalFixture, ['06_befweg' => 'Anderer Weg'])
            ),
    'terminal evidence hash is unstable for metadata or blind to transport data'
);

$payload = "O'Reilly \"quoted\" & <script>alert(1)</script>; ' OR 1=1 --";
$encoded = estab_message_html($payload);
$assert(estab_message_plain_text($payload) === $payload, 'raw UTF-8 payload changed');
$assert(!str_contains($encoded, '<script>'), 'script element survived HTML boundary');
$assert(
    str_contains($encoded, '&lt;script&gt;alert(1)&lt;/script&gt;')
        && str_contains($encoded, '&amp;')
        && str_contains($encoded, 'OR 1=1'),
    'quotes, ampersand, script or SQL-shaped text did not survive safely'
);
$legacy = 'Müller &amp; Söhne &lt;b&gt;alt&lt;/b&gt;';
$assert(
    estab_message_plain_text($legacy) === 'Müller & Söhne <b>alt</b>',
    'legacy entity row was not decoded once'
);
$assert(
    estab_message_html($legacy) === 'Müller &amp; Söhne &lt;b&gt;alt&lt;/b&gt;',
    'legacy entity row did not render safely'
);

$commandPostIncident = [
    'fuehrungsstellenname' => ' FüSt Einsatz 7 ',
];
putenv('ESTAB_ORGANISATION=GLOBAL-CONFIG-MUST-NOT-APPEAR');
$boundIncoming = estab_message_bind_command_post([
    '04_richtung' => 'E',
    '10_anschrift' => 'vom Browser gefälscht',
    '11_gesprnotiz' => 'f',
    '13_abseinheit' => 'Leitstelle Gegenstelle',
], $commandPostIncident);
$assert(
    $boundIncoming['10_anschrift'] === 'FüSt Einsatz 7'
        && $boundIncoming['13_abseinheit'] === 'Leitstelle Gegenstelle',
    'repository did not authoritatively address an incoming message locally'
);
$boundConversationNote = estab_message_bind_command_post([
    '04_richtung' => 'E',
    '10_anschrift' => 'vom Browser gefälscht',
    '11_gesprnotiz' => 't',
    '13_abseinheit' => 'vom Browser gefälscht',
], $commandPostIncident);
$assert(
    $boundConversationNote['10_anschrift'] === 'FüSt Einsatz 7'
        && $boundConversationNote['13_abseinheit'] === 'FüSt Einsatz 7',
    'repository did not bind both sides of an internal conversation note'
);
$boundOutgoing = estab_message_bind_command_post([
    '04_richtung' => 'A',
    '10_anschrift' => 'Leitstelle Gegenstelle',
    '13_abseinheit' => 'vom Browser gefälscht',
], $commandPostIncident);
$assert(
    $boundOutgoing['10_anschrift'] === 'Leitstelle Gegenstelle'
        && $boundOutgoing['13_abseinheit'] === 'FüSt Einsatz 7',
    'repository did not authoritatively bind the outgoing sender'
);
putenv('ESTAB_ORGANISATION');
try {
    estab_message_bind_command_post(
        ['04_richtung' => 'A'],
        [
            'fuehrungsstellenname' => null,
            'organisation' => 'Nicht als Ersatz verwenden',
        ]
    );
    $assert(false, 'repository accepted an incident without command-post identity');
} catch (EstabIncidentConfigurationException) {
    $assert(true, 'repository rejected an incomplete historical incident');
}

$staff = ['kuerzel' => 'st0001', 'funktion' => 'S1', 'rolle' => 'Stab'];
$successorStaff = ['kuerzel' => 'st0003', 'funktion' => 'S1', 'rolle' => 'Stab'];
$secondStaff = ['kuerzel' => 's20001', 'funktion' => 'S2', 'rolle' => 'Stab'];
$foreignStaff = ['kuerzel' => 'st0002', 'funktion' => 'S10', 'rolle' => 'Stab'];
$viewer = ['kuerzel' => 'si0001', 'funktion' => 'Si', 'rolle' => 'Stab'];
$radio = ['kuerzel' => 'aw0001', 'funktion' => 'A/W', 'rolle' => 'Fernmelder'];
$otherRadio = ['kuerzel' => 'aw0002', 'funktion' => 'A/W', 'rolle' => 'Fernmelder'];
$lead = ['kuerzel' => 'ld0001', 'funktion' => 'LdF', 'rolle' => 'Fernmelder'];
$incoming = [
    '04_richtung' => 'E',
    '02_zeit' => '2026-07-23 11:59:00',
    '02_zeichen' => 'ld0001',
    '03_datum' => null,
    '03_zeichen' => '',
    '06_befwegausw' => '',
    '15_quitdatum' => null,
    '15_quitzeichen' => '',
    '16_empf' => 'S1_rt,S2_bl',
    'x00_status' => 4,
    'x01_abschluss' => 'f',
    'x02_sperre' => 'f',
    'x03_sperruser' => '',
];
$outgoing = $incoming + [];
$outgoing['04_richtung'] = 'A';
$outgoing['x00_status'] = 2;
$outgoing['06_befwegausw'] = 'Fu';
$outgoing['15_quitdatum'] = '2026-07-23 11:58:00';
$outgoing['15_quitzeichen'] = 'si0001';
$outgoing['x02_sperre'] = 't';
$outgoing['x03_sperruser'] = 'aw0001';
$leadIncoming = $incoming;
$leadIncoming['02_zeit'] = null;
$leadIncoming['02_zeichen'] = '';
$leadIncoming['x00_status'] = 1;
$leadIncoming['x02_sperre'] = 't';
$leadIncoming['x03_sperruser'] = 'ld0001';
$leadOutgoing = $leadIncoming;
$leadOutgoing['04_richtung'] = 'A';
$leadOutgoing['15_quitdatum'] = '2026-07-23 11:58:00';
$leadOutgoing['15_quitzeichen'] = 'si0001';
$pendingOutgoingReview = $leadIncoming;
$pendingOutgoingReview['04_richtung'] = 'A';
$pendingOutgoingReview['x00_status'] = 4;
$pendingOutgoingReview['x02_sperre'] = 'f';
$pendingOutgoingReview['x03_sperruser'] = '';
$pendingOutgoingReview['14_zeichen'] = 'st0001';
$pendingOutgoingReview['14_funktion'] = 'S1';
$returnedOutgoing = $pendingOutgoingReview;
$returnedOutgoing['x00_status'] = 10;
$returnedOutgoing['14_zeichen'] = 'st0001';
$returnedOutgoing['14_funktion'] = 'S1';
$returnedOutgoing['15_quitdatum'] = '2026-07-23 11:58:00';
$returnedOutgoing['15_quitzeichen'] = 'si0001';

$assert(
    !estab_message_object_allowed($staff, 'staff-read', $incoming)
        && !estab_message_object_allowed($staff, 'staff-state', $incoming),
    'staff reached an incoming recipient before terminal Si review'
);
$assert(!estab_message_object_allowed($foreignStaff, 'staff-read', $incoming), 'substring recipient accepted');
$assert(estab_message_object_allowed($viewer, 'viewer-review', $incoming), 'pending viewer item denied');
$completedIncoming = $incoming;
$completedIncoming['x00_status'] = 8;
$completedIncoming['x01_abschluss'] = 't';
$completedIncoming['15_quitdatum'] = '2026-07-23 12:01:00';
$completedIncoming['15_quitzeichen'] = 'si0001';
$assert(
    estab_message_object_allowed($staff, 'staff-read', $completedIncoming)
        && estab_message_object_allowed(
            $staff,
            'staff-state',
            $completedIncoming
        ),
    'terminal incoming recipient denied'
);
$assert(
    estab_message_object_allowed(
        $viewer,
        'viewer-review',
        $pendingOutgoingReview
    ),
    'outgoing form was not offered to Si before LdF'
);
$assert(
    estab_message_object_allowed(
        $staff,
        'staff-read',
        $pendingOutgoingReview
    )
        && estab_message_object_allowed(
            $staff,
            'staff-state',
            $pendingOutgoingReview
        )
        && !estab_message_object_allowed(
            $secondStaff,
            'staff-read',
            $pendingOutgoingReview
        )
        && !estab_message_object_allowed(
            $secondStaff,
            'staff-state',
            $pendingOutgoingReview
        ),
    'unreviewed outgoing object was not limited to its function author'
);
$assert(
    !estab_message_object_allowed(
        $lead,
        'telecommunications-lead-edit',
        $pendingOutgoingReview
    ),
    'LdF bypassed mandatory outgoing formal review'
);
$assert(
    estab_message_object_allowed(
        $staff,
        'staff-correction',
        $returnedOutgoing
    )
        && estab_message_object_allowed(
            $successorStaff,
            'staff-correction',
            $returnedOutgoing
        )
        && !estab_message_object_allowed(
            $foreignStaff,
            'staff-correction',
            $returnedOutgoing
        ),
    'returned outgoing form did not follow the responsible function'
);
$assert(
    estab_message_object_allowed($staff, 'staff-read', $returnedOutgoing)
        && !estab_message_object_allowed(
            $secondStaff,
            'staff-read',
            $returnedOutgoing
        )
        && !estab_message_object_allowed(
            $secondStaff,
            'staff-state',
            $returnedOutgoing
        ),
    'returned outgoing object leaked beyond its function author'
);
$completedOutgoing = $returnedOutgoing;
$completedOutgoing['x00_status'] = 8;
$completedOutgoing['x01_abschluss'] = 't';
$assert(
    estab_message_object_allowed(
        $secondStaff,
        'staff-read',
        $completedOutgoing
    )
        && estab_message_object_allowed(
            $secondStaff,
            'staff-state',
            $completedOutgoing
        ),
    'terminal outgoing recipient denied'
);
$assert(
    !estab_message_object_allowed($staff, 'staff-read', $leadIncoming),
    'untranslated incoming item reached recipient queue'
);
$assert(
    estab_message_object_allowed(
        $lead,
        'telecommunications-lead-edit',
        $leadOutgoing
    )
        && estab_message_object_allowed(
            $lead,
            'telecommunications-lead-incoming-save',
            $leadIncoming
        )
        && estab_message_object_allowed(
            $lead,
            'telecommunications-lead-outgoing-save',
            $leadOutgoing
        ),
    'LdF staged object permissions are incomplete'
);
$assert(
    !estab_message_object_allowed(
        $radio,
        'telecommunications-edit',
        $leadOutgoing
    )
        && !estab_message_object_allowed(
            $lead,
            'telecommunications-lead-edit',
            $outgoing
        ),
    'LdF and A/W stages overlap'
);
$assert(estab_message_object_allowed($radio, 'telecommunications-edit', $outgoing), 'pending outgoing denied');
$assert(estab_message_object_allowed($radio, 'telecommunications-save', $outgoing), 'lock owner denied');
$assert(!estab_message_object_allowed($otherRadio, 'telecommunications-save', $outgoing), 'foreign lock accepted');
$assert(
    !estab_workflow_route_allowed(
        $radio,
        'POST',
        ['task' => 'FM-Admin', '00_lfd' => '1', 'absenden_x' => '1']
    )
        && !estab_workflow_route_allowed(
            $viewer,
            'POST',
            ['task' => 'SI-Admin', '00_lfd' => '1', 'absenden_x' => '1']
        ),
    'completed-message administration still accepts mutation tasks'
);
$assert(
    estab_workflow_route_allowed(
        $radio,
        'POST',
        ['fm' => 'FM-Adminmeldung', '00_lfd' => '1']
    )
        && estab_workflow_route_allowed(
            $viewer,
            'POST',
            ['fm' => 'SI-Adminmeldung', '00_lfd' => '1']
        )
        && estab_workflow_message_operation(
            ['fm' => 'FM-Adminmeldung', '00_lfd' => '1']
        ) === 'telecommunications-admin'
        && estab_message_object_allowed($radio, 'telecommunications-admin', $incoming)
        && !estab_message_object_allowed($staff, 'telecommunications-admin', $incoming),
    'read-only administration is not restricted to its role and message'
);
$transported = $outgoing;
$transported['03_datum'] = '2026-07-23 12:00:00';
$transported['03_zeichen'] = 'aw0001';
$assert(!estab_message_object_allowed($radio, 'telecommunications-save', $transported), 'transported row remained editable');
$assert(estab_message_object_allowed($otherRadio, 'message-operator-reset', $outgoing), 'locked pending reset denied');
$recipientPattern = estab_message_recipient_pattern('S1');
$assert(preg_match('/' . $recipientPattern . '/', 'S1_rt,S2_bl') === 1, 'exact SQL recipient pattern rejected');
$assert(preg_match('/' . $recipientPattern . '/', 'S10_rt') === 0, 'SQL recipient pattern accepted substring');

$root = dirname(__DIR__, 2);
$repositorySource = file_get_contents($root . '/app/message_repository.php');
$dataSource = file_get_contents($root . '/4fach/data_hndl.php');
$mainSource = file_get_contents($root . '/4fach/mainindex.php');
$formSource = file_get_contents($root . '/4fach/4fachform.php');
$listSource = file_get_contents($root . '/4fach/liste.php');
$allMessagesSource = file_get_contents($root . '/4fach/all_msg.php');
$overviewSource = file_get_contents($root . '/4fueltg/ue_ltg.php');
$pdfSource = file_get_contents($root . '/4fbak/backup_pdf.php');
$concurrencySource = file_get_contents($root . '/tests/integration/message_concurrency.php');
$incidentSource = file_get_contents($root . '/app/incident.php');
$incidentConfigSource = file_get_contents($root . '/4fcfg/e_cfg.inc.php');
$environmentSource = file_get_contents($root . '/.env.example');
$composeSource = file_get_contents($root . '/compose.yaml');
$registryEnvironmentSource = file_get_contents(
    $root . '/deploy/registry/.env.example'
);
$registryComposeSource = file_get_contents(
    $root . '/deploy/registry/compose.yaml'
);
foreach ([
    $repositorySource, $dataSource, $mainSource, $formSource,
    $listSource, $allMessagesSource, $overviewSource, $pdfSource, $concurrencySource,
    $incidentSource, $incidentConfigSource, $environmentSource, $composeSource,
    $registryEnvironmentSource, $registryComposeSource,
] as $source) {
    $assert(is_string($source), 'security source unreadable');
}

$assert(
    substr_count(
        $listSource,
        'estab_recipient_copy_cell_html ('
    ) === 1
        && substr_count(
            $listSource,
            '$empfcolor [$recipientFunction] ?? ""'
        ) === 1
        && str_contains(
            $listSource,
            'estab_message_list_render_table ('
        )
        && str_contains(
            $listSource,
            'estab_recipient_copy_background ('
        )
        && !str_contains($listSource, '$abfzeit[stak]')
        && str_contains($listSource, 'switch ( $row["x00_status"] )')
        && !str_contains($listSource, '$row["X00_status"]'),
    'message lists do not handle missing/multicolour recipients or timestamp/status keys safely'
);
$assert(
    str_contains($allMessagesSource, 'http_response_code(410)')
        && strpos($allMessagesSource, 'exit;')
            < strpos($allMessagesSource, 'include ('),
    'retired unrestricted all-message renderer can still execute'
);
$assert(
    substr_count($listSource, 'data-estab-list-filter') === 2
        && substr_count($listSource, '<form') === 4
        && substr_count($listSource, '</form>') === 4
        && substr_count($listSource, 'echo "<tr".$priorityStyle') === 2
        && str_contains(
            $listSource,
            'estab_message_list_render_controls ('
        )
        && substr_count($listSource, 'echo "<tr>\n";') >= 3
        && substr_count($listSource, 'echo "</th>\n";') >= 3,
    'message lists contain nested filters or structurally incomplete table rows'
);
$assert(
    str_contains(
        $listSource,
        '$visibility = estab_read_message_visibility_sql ($identity, "m")'
    )
        && str_contains($listSource, '"SELECT COUNT(*) FROM ".$messageTable')
        && str_contains($listSource, '" LIMIT ? OFFSET ?"')
        && str_contains(
            $listSource,
            '$verified = estab_read_filter_messages ($result, $identity)'
        )
        && str_contains(
            $listSource,
            'SQL/PHP visibility drift in second-sighting message list'
        )
        && str_contains($listSource, 'estab_message_list_render_resultbar (')
        && str_contains($listSource, 'estab_message_list_render_pager ('),
    'second-sighting lists do not page inside their object-level read boundary'
);

$fmAdminAccess = [];
$fmAdminButtons = [];
$assert(
    preg_match(
        '/case\s+"FM-Admin"\s*:\s*case\s+"SI-Admin"\s*:(?<body>.*?)break;/s',
        $formSource,
        $fmAdminAccess
    ) === 1
        && !str_contains($fmAdminAccess['body'], '$this->feld [')
        && !str_contains($fmAdminAccess['body'], '$this->bg ['),
    'completed-message administration still enables editable fields'
);
$assert(
    preg_match(
        '/case "FM-Admin":\s*case "SI-Admin":(?<body>.*?)break;/s',
        $formSource,
        $fmAdminButtons
    ) === 1
        && str_contains(
            $fmAdminButtons['body'],
            'Abgeschlossener Nachweis – '
        )
        && !str_contains($fmAdminButtons['body'], 'name=\\"absenden')
        && !str_contains($fmAdminButtons['body'], 'name=\\"task\\"'),
    'completed-message administration is not a read-only evidence view'
);
$assert(
    !preg_match(
        '/case "FM-Admin":\s*case "SI-Admin":(?<body>.*?)\s+break;/s',
        $dataSource
    ),
    'completed-message administration still has a mutation handler'
);
$assert(
    str_contains($formSource, 'data-estab-formal-review=')
        && str_contains($formSource, 'name=\\"zurueckweisen_x\\"')
        && str_contains($formSource, 'Formal geprüft – an FmZt')
        && str_contains($formSource, 'An Verfasser zurückgeben'),
    'mandatory outgoing formal-review controls are missing'
);
$assert(
    str_contains($mainSource, '($returnValue ["task"] ?? "") !== ""')
        && str_contains($mainSource, 'estab_csrf_require_post ($_SERVER, $_POST);')
        && !str_contains($mainSource, '$returnValue["task"] == "FM-Admin"')
        && !str_contains($mainSource, '$returnValue["task"] == "SI-Admin"'),
    'controller still dispatches completed-message administration writes'
);

$assert(
    str_contains($repositorySource, '->prepare($sql)')
        && str_contains($repositorySource, 'SELECT GET_LOCK(?, 10)')
        && str_contains($repositorySource, "require_once __DIR__ . '/datetime.php'"),
    'prepared/concurrent repository contract missing'
);
$assert(
    str_contains(
        $repositorySource,
        'SELECT `14_zeichen`, `14_funktion`, `17_vermerke` FROM '
    )
        && str_contains(
            $repositorySource,
            "\$event['snapshot']['correction_note'] ="
        )
        && !str_contains(
            $dataSource,
            '"correction_note" => $data ["17_vermerke"]'
        ),
    'author resubmission evidence accepts a browser-selected correction note'
);
$assert(
    str_contains(
        $repositorySource,
        'function estab_message_bind_command_post('
    )
        && substr_count(
            $repositorySource,
            'estab_message_bind_command_post('
        ) >= 4
        && str_contains(
            $repositorySource,
            '$commandPostName = estab_incident_command_post_name($incident);'
        )
        && str_contains(
            $mainSource,
            '$activeCommandPostName = estab_incident_command_post_name ('
        )
        && str_contains(
            $dataSource,
            'function check_and_save ($data, $activeCommandPostName)'
        )
        && str_contains(
            $dataSource,
            'estab_incident_command_post_name ($messageIncident);'
        ),
    'incident command-post identity is not authoritative at repository writes'
);
$assert(
    !str_contains(
        implode("\n", [
            $repositorySource,
            $dataSource,
            $mainSource,
            $incidentSource,
            $incidentConfigSource,
            $environmentSource,
            $composeSource,
            $registryEnvironmentSource,
            $registryComposeSource,
        ]),
        'ESTAB_ORGANISATION'
    ),
    'installation environment still acts as a command-post identity source'
);
$assert(
    !str_contains($repositorySource, 'affected_rows >= 0')
        && str_contains($repositorySource, 'estab_message_state_set_for_recipient')
        && str_contains($repositorySource, 'SELECT DISTINCT m.`00_lfd`')
        && str_contains($repositorySource, 'estab_message_counter_lock_name'),
    'conditional update/state/counter contracts are incomplete'
);
$assert(
    str_contains(
        $repositorySource,
        'function estab_message_counter_repair_max('
    )
        && str_contains(
            $repositorySource,
            "'message_counter_repaired'"
        )
        && str_contains(
            $repositorySource,
            'max($messageMaximum, $repairMaximum) + 1'
        ),
    'normal allocation ignores the immutable administrative counter watermark'
);
$assert(
    str_contains(
        $repositorySource,
        "AND `x01_abschluss` = 'f' AND `x00_status` <> 8"
    )
        && str_contains($repositorySource, 'estab_dv_resolve_active_route')
        && str_contains(
            $repositorySource,
            "'estab_fernmeldeplan_eintrag_id'"
        )
        && str_contains(
            $repositorySource,
            'estab_dv_require_messenger_reported_for_message'
        )
        && str_contains(
            $repositorySource,
            'estab_message_require_attachment_scope('
        )
        && str_contains(
            $repositorySource,
            'Message attachment table is required for resubmission'
        )
        && str_contains(
            $dataSource,
            '$conf_4f_tbl ["anhang"]'
        )
        && str_contains($repositorySource, "'messenger_job_id'"),
    'closed-row immutability, attachment, S6 route or messenger evidence gate is missing'
);
$assert(
    !str_contains($dataSource, 'htmlentities ($data ["12_inhalt"]')
        && !str_contains($dataSource, 'query_table_iu ($query)')
        && str_contains($dataSource, 'estab_message_state_set_for_recipient')
        && str_contains($dataSource, 'estab_message_state_unset_for_recipient')
        && str_contains(
            $dataSource,
            '$data ["16_empf"] = $redcopy2."_rt,";'
        )
        && str_contains(
            $repositorySource,
            'function estab_message_staff_access_sql('
        )
        && str_contains(
            $repositorySource,
            '$isTerminalStaffRecipient || $isOutgoingAuthor'
        )
        && str_contains(
            $listSource,
            'estab_message_staff_access_sql ("m")'
        ),
    'message writer still entity-encodes, concatenates writes or bypasses state ownership'
);
$assert(
    !str_contains($mainSource, 'SET x02_sperre =')
        && !str_contains($mainSource, '4fachkatego_absenden_x')
        && str_contains($mainSource, 'estab_message_object_allowed'),
    'controller bypasses lock/object/category boundaries'
);
$assert(
    str_contains($formSource, 'safe_message_value')
        && str_contains($listSource, 'estab_list_detail_action')
        && str_contains($listSource, 'estab_message_query_rows')
        && !str_contains($listSource, 'LIKE \"%".$_SESSION["flt_search"]'),
    'message form/list output or search boundary is incomplete'
);
$assert(
    str_contains($formSource, '$errorDefaults = array_fill_keys')
        && str_contains(
            $formSource,
            'is_array ($errorselect) ? $errorselect : array ()'
        ),
    'partial validation errors can trigger undefined form-field warnings'
);
$assert(
    str_contains($overviewSource, 'estab_message_positive_id')
        && str_contains($overviewSource, 'estab_message_query_rows')
        && !str_contains($overviewSource, 'LIKE \"%".$_SESSION["ueb_flt_search"]')
        && str_contains(
            $overviewSource,
            '$overviewReadScope ["incident"]["active_einsatz_id"]'
        )
        && str_contains($overviewSource, '.`einsatz_id` = ?')
        && str_contains(
            $overviewSource,
            'estab_message_fetch_for_incident_by_id ('
        )
        && !str_contains(
            $overviewSource,
            '(SELECT `active_einsatz_id` FROM `nv_einsatz_status`'
        ),
    'overview ID/search boundary is incomplete'
);
$fetchWrapperStart = strpos(
    $repositorySource,
    'function estab_message_fetch_by_id('
);
$fetchWrapperEnd = strpos(
    $repositorySource,
    '/** Run a prepared read query',
    $fetchWrapperStart === false ? 0 : $fetchWrapperStart
);
$fetchWrapper = (
    $fetchWrapperStart !== false
    && $fetchWrapperEnd !== false
    && $fetchWrapperEnd > $fetchWrapperStart
) ? substr(
    $repositorySource,
    $fetchWrapperStart,
    $fetchWrapperEnd - $fetchWrapperStart
) : '';
$assert(
    $fetchWrapper !== ''
        && strpos($fetchWrapper, 'estab_message_positive_id($recordId)')
            < strpos($fetchWrapper, 'estab_incident_active($connection)'),
    'compatibility message lookup resolves ambient state before validating its ID'
);
$assert(
    str_contains(
        $repositorySource,
        "AND BINARY ttb_row.`estab_entry_type` = BINARY 'nachricht'"
    )
        && str_contains($repositorySource, 'AS `estab_ttb_lfd`')
        && str_contains(
            $repositorySource,
            'ORDER BY ttb_row.`estab_book_lfd`,'
        )
        && str_contains($repositorySource, 'ttb_row.`tbb_lfd-nr` LIMIT 1)'),
    'message detail lookup does not select the first exact incident-local TBB evidence row'
);
$assert(
    str_contains(
        $mainSource,
        'new listen ("Stab_sichten", "STSI", $workflowIncidentId)'
    )
        && str_contains(
            $mainSource,
            'new listen ("LDF", "", $workflowIncidentId)'
        )
        && str_contains(
            $mainSource,
            'new listen ("FMA", "", $workflowIncidentId)'
        )
        && !str_contains($mainSource, 'estab_message_fetch_by_id ('),
    'main workflow re-resolves the active incident after its authorization gate'
);
$assert(
    str_contains($pdfSource, 'estab_message_plain_text')
        && str_contains($pdfSource, 'estab_fpdf_text'),
    'PDF compatibility/output boundary missing'
);
$assert(
    str_contains($concurrencySource, "message_db_start_worker('numbered'")
        && preg_match(
            "/message_db_start_worker\\(\\s*'state'/",
            $concurrencySource
        ) === 1
        && str_contains($concurrencySource, "'ldf-save'")
        && str_contains($concurrencySource, "'aw-save'")
        && str_contains(
            $concurrencySource,
            'estab_message_update_locked_operator_stage'
        )
        && str_contains(
            $concurrencySource,
            'estab_message_acquire_operator_stage_lock'
        )
        && str_contains($concurrencySource, 'estab_admin_acquire_counter_lock'),
    'message concurrency integration coverage is incomplete'
);

printf("Message security tests: OK (%d assertions)\n", $assertions);
