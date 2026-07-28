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

$staff = ['kuerzel' => 'st0001', 'funktion' => 'S1', 'rolle' => 'Stab'];
$foreignStaff = ['kuerzel' => 'st0002', 'funktion' => 'S10', 'rolle' => 'Stab'];
$viewer = ['kuerzel' => 'si0001', 'funktion' => 'Si', 'rolle' => 'Stab'];
$radio = ['kuerzel' => 'aw0001', 'funktion' => 'A/W', 'rolle' => 'Fernmelder'];
$otherRadio = ['kuerzel' => 'aw0002', 'funktion' => 'A/W', 'rolle' => 'Fernmelder'];
$incoming = [
    '04_richtung' => 'E',
    '03_datum' => null,
    '03_zeichen' => '',
    '15_quitdatum' => null,
    '15_quitzeichen' => '',
    '16_empf' => 'S1_rt,S2_bl',
    'x02_sperre' => 'f',
    'x03_sperruser' => '',
];
$outgoing = $incoming + [];
$outgoing['04_richtung'] = 'A';
$outgoing['x02_sperre'] = 't';
$outgoing['x03_sperruser'] = 'aw0001';

$assert(estab_message_object_allowed($staff, 'staff-read', $incoming), 'recipient denied');
$assert(!estab_message_object_allowed($foreignStaff, 'staff-read', $incoming), 'substring recipient accepted');
$assert(estab_message_object_allowed($viewer, 'viewer-review', $incoming), 'pending viewer item denied');
$assert(estab_message_object_allowed($radio, 'telecommunications-edit', $outgoing), 'pending outgoing denied');
$assert(estab_message_object_allowed($radio, 'telecommunications-save', $outgoing), 'lock owner denied');
$assert(!estab_message_object_allowed($otherRadio, 'telecommunications-save', $outgoing), 'foreign lock accepted');
$assert(
    estab_workflow_route_allowed(
        $radio,
        'POST',
        ['task' => 'FM-Admin', '00_lfd' => '1', 'absenden_x' => '1']
    )
        && !estab_workflow_route_allowed(
            $staff,
            'POST',
            ['task' => 'FM-Admin', '00_lfd' => '1', 'absenden_x' => '1']
        ),
    'FM admin submit is not restricted to the A/W role'
);
$assert(
    estab_workflow_message_operation(['task' => 'FM-Admin', '00_lfd' => '1'])
        === 'telecommunications-admin'
        && estab_message_object_allowed($radio, 'telecommunications-admin', $incoming)
        && !estab_message_object_allowed($staff, 'telecommunications-admin', $incoming),
    'FM admin submit bypasses its message-object permission'
);
$transported = $outgoing;
$transported['03_datum'] = '2026-07-23 12:00:00';
$transported['03_zeichen'] = 'aw0001';
$assert(!estab_message_object_allowed($radio, 'telecommunications-save', $transported), 'transported row remained editable');
$assert(estab_message_object_allowed($otherRadio, 'telecommunications-reset', $outgoing), 'locked pending reset denied');
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
foreach ([
    $repositorySource, $dataSource, $mainSource, $formSource,
    $listSource, $allMessagesSource, $overviewSource, $pdfSource, $concurrencySource,
] as $source) {
    $assert(is_string($source), 'security source unreadable');
}

$assert(
    substr_count($listSource, "switch (\$empfcolor [\$recipientFunction] ?? '')") === 2
        && substr_count($allMessagesSource, "switch (\$empfcolor [\$recipientFunction] ?? '')") === 1
        && !str_contains($listSource, '$abfzeit[stak]'),
    'message lists do not handle missing recipient colors or timestamp keys safely'
);

$fmAdminAccess = [];
$fmAdminButtons = [];
$fmAdminUpdate = [];
$assert(
    preg_match(
        '/case\s+"FM-Admin"\s*:\s*case\s+"SI-Admin"\s*:(?<body>.*?)break;/s',
        $formSource,
        $fmAdminAccess
    ) === 1
        && str_contains($fmAdminAccess['body'], 'for ($i=15;$i<=17;$i++)')
        && !str_contains($fmAdminAccess['body'], 'for ($i=1;$i<=17;$i++)'),
    'FM admin form exposes fields outside the persisted review section'
);
$assert(
    preg_match(
        '/case\s+"FM-Admin"\s*:\s*case\s+"Stab_sichten"\s*:\s*case\s+"SI-Admin"\s*:(?<body>.*?)break;/s',
        $formSource,
        $fmAdminButtons
    ) === 1
        && str_contains($fmAdminButtons['body'], 'name=\\"task\\"')
        && str_contains($fmAdminButtons['body'], 'name=\\"absenden\\"')
        && str_contains($fmAdminButtons['body'], 'name=\\"abbrechen\\"'),
    'FM admin form has no controller-compatible submit controls'
);
$assert(
    preg_match(
        '/case "FM-Admin":\s*case "SI-Admin":(?<body>.*?)\s+break;/s',
        $dataSource,
        $fmAdminUpdate
    ) === 1
        && str_contains($fmAdminUpdate['body'], '"15_quitzeichen"')
        && str_contains($fmAdminUpdate['body'], '"16_empf"')
        && str_contains($fmAdminUpdate['body'], '"17_vermerke"')
        && !str_contains($fmAdminUpdate['body'], '"12_inhalt"'),
    'FM admin handler and editable review fields are no longer aligned'
);
$assert(
    str_contains($mainSource, '($returnValue ["task"] ?? "") !== ""')
        && str_contains($mainSource, 'estab_csrf_require_post ($_SERVER, $_POST);')
        && str_contains($mainSource, '( $returnValue["task"] == "FM-Admin" )'),
    'FM admin submit is not covered by the authenticated CSRF save gate'
);

$assert(
    str_contains($repositorySource, '->prepare($sql)')
        && str_contains($repositorySource, 'SELECT GET_LOCK(?, 10)')
        && str_contains($repositorySource, "require_once __DIR__ . '/datetime.php'"),
    'prepared/concurrent repository contract missing'
);
$assert(
    !str_contains($repositorySource, 'affected_rows >= 0')
        && str_contains($repositorySource, 'estab_message_state_set_for_recipient')
        && str_contains($repositorySource, 'SELECT DISTINCT m.`00_lfd`')
        && str_contains($repositorySource, 'estab_message_counter_lock_name'),
    'conditional update/state/counter contracts are incomplete'
);
$assert(
    !str_contains($dataSource, 'htmlentities ($data ["12_inhalt"]')
        && !str_contains($dataSource, 'query_table_iu ($query)')
        && str_contains($dataSource, 'estab_message_state_set_for_recipient')
        && str_contains($dataSource, 'estab_message_state_unset_for_recipient'),
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
    str_contains($overviewSource, 'estab_message_positive_id')
        && str_contains($overviewSource, 'estab_message_query_rows')
        && !str_contains($overviewSource, 'LIKE \"%".$_SESSION["ueb_flt_search"]'),
    'overview ID/search boundary is incomplete'
);
$assert(
    str_contains($pdfSource, 'estab_message_plain_text')
        && str_contains($pdfSource, 'estab_fpdf_text'),
    'PDF compatibility/output boundary missing'
);
$assert(
    str_contains($concurrencySource, "message_db_start_worker('numbered'")
        && str_contains($concurrencySource, "message_db_start_worker('state'")
        && str_contains($concurrencySource, "message_db_start_worker('save'")
        && str_contains($concurrencySource, "message_db_start_worker('reset'")
        && str_contains($concurrencySource, 'estab_admin_acquire_counter_lock'),
    'message concurrency integration coverage is incomplete'
);

printf("Message security tests: OK (%d assertions)\n", $assertions);
