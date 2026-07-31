<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/read_authorization.php';
require_once dirname(__DIR__, 2) . '/app/navigation.php';

$assertions = 0;
$assert = static function (
    bool $condition,
    string $message
) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$identity = static fn (
    string $code,
    string $function,
    string $role,
    bool $selected = true
): array => [
    'benutzer' => $function . ' Test',
    'kuerzel' => $code,
    'funktion' => $function,
    'rolle' => $role,
] + ($selected ? ['duty_assignment_id' => 17] : []);

$terminal = [
    '04_richtung' => 'E',
    '16_empf' => 'S2_rt,S1_gn,POL_bl,',
    'x00_status' => 8,
    'x01_abschluss' => 't',
    'x02_sperre' => 'f',
    'x03_sperruser' => '',
    '01_datum' => '2026-07-30 10:00:00',
    '01_zeichen' => 'aw0001',
    '02_zeit' => '2026-07-30 10:01:00',
    '02_zeichen' => 'ldf001',
    '03_datum' => null,
    '03_zeichen' => '',
    '06_befwegausw' => 'Fu',
    '14_zeichen' => '',
    '14_funktion' => '',
    '15_quitdatum' => '2026-07-30 10:02:00',
    '15_quitzeichen' => 'si0001',
];
$s1 = $identity('s10001', 'S1', 'Stab');
$s10 = $identity('s10010', 'S10', 'Stab');
$etb = $identity('etb001', 'ETB', 'Stab');
$assert(
    estab_read_identity_capability($etb) === 'EINSATZTAGEBUCH',
    'ETB hat is not bound to its narrow database capability'
);
$assert(
    estab_read_message_allowed($s1, $terminal),
    'terminal exact recipient lost read access'
);
$assert(
    !estab_read_message_allowed($s10, $terminal),
    'substring recipient gained read access'
);
$assert(
    !estab_read_message_allowed(
        $identity('s10001', 'S1', 'Stab', false),
        $terminal
    ),
    'an unselected primary account gained object access'
);

$draft = $terminal;
$draft['04_richtung'] = 'A';
$draft['x00_status'] = 4;
$draft['x01_abschluss'] = 'f';
$draft['14_zeichen'] = 's10001';
$draft['14_funktion'] = 'S1';
$draft['15_quitdatum'] = null;
$draft['15_quitzeichen'] = '';
$assert(
    estab_read_message_allowed($s1, $draft),
    'outgoing author lost their own nonterminal object'
);
$assert(
    !estab_read_message_allowed(
        $identity('s30001', 'S3', 'Stab'),
        $draft
    ),
    'foreign staff function gained draft access'
);

$pendingReview = $terminal;
$pendingReview['x00_status'] = 4;
$pendingReview['x01_abschluss'] = 'f';
$pendingReview['15_quitdatum'] = null;
$pendingReview['15_quitzeichen'] = '';
$si = $identity('si0001', 'Si', 'Stab');
$assert(
    estab_read_message_allowed($si, $pendingReview),
    'Si review queue was hidden'
);
$assert(
    estab_read_message_allowed($si, $terminal),
    'Si lost an object carrying their review mark'
);
$terminalOtherSi = $terminal;
$terminalOtherSi['15_quitzeichen'] = 'si0002';
$assert(
    !estab_read_message_allowed($si, $terminalOtherSi),
    'Si gained another reviewer’s completed object'
);

$pendingLead = $terminal;
$pendingLead['x00_status'] = 1;
$pendingLead['x01_abschluss'] = 'f';
$pendingLead['02_zeit'] = null;
$pendingLead['02_zeichen'] = '';
$pendingLead['03_datum'] = null;
$pendingLead['03_zeichen'] = '';
$pendingLead['15_quitdatum'] = null;
$pendingLead['15_quitzeichen'] = '';
$ldf = $identity('ldf001', 'LdF', 'Fernmelder');
$assert(
    estab_read_message_allowed($ldf, $pendingLead),
    'LdF disposition queue was hidden'
);
$assert(
    estab_read_message_allowed($ldf, $terminal),
    'LdF lost an object carrying their acceptance mark'
);
$terminalOtherLdf = $terminal;
$terminalOtherLdf['02_zeichen'] = 'ldf002';
$assert(
    !estab_read_message_allowed($ldf, $terminalOtherLdf),
    'LdF gained another lead’s completed object'
);

$pendingTransport = $terminal;
$pendingTransport['04_richtung'] = 'A';
$pendingTransport['x00_status'] = 2;
$pendingTransport['x01_abschluss'] = 'f';
$pendingTransport['03_datum'] = null;
$pendingTransport['03_zeichen'] = '';
$aw = $identity('aw0001', 'A/W', 'Fernmelder');
$assert(
    estab_read_message_allowed($aw, $pendingTransport),
    'A/W transport queue was hidden'
);
$assert(
    estab_read_message_allowed($aw, $terminal),
    'A/W lost an incoming object carrying their intake mark'
);
$transported = $pendingTransport;
$transported['x00_status'] = 8;
$transported['x01_abschluss'] = 't';
$transported['01_zeichen'] = '';
$transported['03_datum'] = '2026-07-30 10:03:00';
$transported['03_zeichen'] = 'aw0001';
$assert(
    estab_read_message_allowed($aw, $transported),
    'A/W lost an object carrying their transport mark'
);
$transported['03_zeichen'] = 'aw0002';
$assert(
    !estab_read_message_allowed($aw, $transported),
    'A/W gained another operator’s completed object'
);

$assert(
    estab_read_attachment_tokens(
        'EL0001.pdf; EL00010.pdf;EL0001.pdf;../secret.pdf;'
    ) === ['EL0001.pdf', 'EL00010.pdf'],
    'attachment token parser is not exact, canonical, and deduplicated'
);
$freeAttachment = [
    'filename' => 'EL0001',
    'fileext' => 'pdf',
    'kuerzel' => 's10001',
];
$assert(
    estab_read_free_attachment_allowed($s1, $freeAttachment),
    'nv_anhang.kuerzel uploader lost their free attachment'
);
$assert(
    !estab_read_free_attachment_allowed(
        $s1,
        [
            'filename' => 'EL0001',
            'fileext' => 'pdf',
            'usercode' => 's10001',
        ]
    ),
    'non-schema usercode field was treated as uploader evidence'
);
foreach ([$identity('s20001', 'S2', 'Stab'), $si, $ldf] as $privileged) {
    $assert(
        estab_read_free_attachment_allowed(
            $privileged,
            array_replace($freeAttachment, ['kuerzel' => 'other1'])
        ),
        'document/review/supervision role lost free-attachment access'
    );
}
$assert(
    !estab_read_free_attachment_allowed(
        $aw,
        array_replace($freeAttachment, ['kuerzel' => 'other1'])
    ),
    'A/W gained an unrelated free attachment'
);
$assert(
    !estab_read_attachment_allowed(
        $s1,
        $freeAttachment,
        [array_replace(
            $terminalOtherSi,
            ['16_empf' => 'S2_rt,S3_gn,']
        )]
    ),
    'uploader bypassed rights inherited from a linked foreign message'
);
$assert(
    estab_read_attachment_allowed($s1, $freeAttachment, [$terminal]),
    'attachment did not inherit an authorized linked message'
);
$assert(
    estab_read_attachment_filename($freeAttachment) === 'EL0001.pdf',
    'attachment row did not produce its complete canonical filename'
);

$overview = estab_navigation_item_for_key('message-overview');
$tracking = estab_navigation_item_for_key('tracking');
$assert(
    is_array($overview)
        && !estab_navigation_duty_access_allowed($overview, $s1)
        && !estab_navigation_duty_access_allowed($overview, $etb)
        && estab_navigation_duty_access_allowed(
            $overview,
            $identity('s20001', 'S2', 'Stab')
        ),
    'overview navigation is not limited to selected S2/LAGE_DOKUMENTATION'
);
$assert(
    is_array($tracking)
        && !estab_navigation_duty_access_allowed($tracking, $s1)
        && estab_navigation_duty_access_allowed($tracking, $ldf)
        && estab_navigation_duty_access_allowed($tracking, $aw),
    'tracking navigation is not limited to selected LdF/A-W'
);

$root = dirname(__DIR__, 2);
$sources = [
    'overview' => (string) file_get_contents(
        $root . '/4fueltg/ue_ltg.php'
    ),
    'tracking' => (string) file_get_contents(
        $root . '/4fach/nachwea.php'
    ),
    'forms' => (string) file_get_contents(
        $root . '/4fach/vordrucke.php'
    ),
    'download' => (string) file_get_contents(
        $root . '/4fach/download.php'
    ),
    'preview' => (string) file_get_contents(
        $root . '/4fach/showpic.php'
    ),
    'attachments' => (string) file_get_contents(
        $root . '/4fach/anhang.php'
    ),
    'messages' => (string) file_get_contents(
        $root . '/app/message_repository.php'
    ),
    'message-handler' => (string) file_get_contents(
        $root . '/4fach/data_hndl.php'
    ),
    'message-controller' => (string) file_get_contents(
        $root . '/4fach/mainindex.php'
    ),
    'message-list' => (string) file_get_contents(
        $root . '/4fach/liste.php'
    ),
    'sidebar' => (string) file_get_contents(
        $root . '/app/sidebar.php'
    ),
    'command-post' => (string) file_get_contents(
        $root . '/4fach/fuehrungsstelle.php'
    ),
    'etb' => (string) file_get_contents(
        $root . '/stabetb/etb.php'
    ),
    'tbb' => (string) file_get_contents(
        $root . '/fmtbb/tbb.php'
    ),
    'categories' => (string) file_get_contents(
        $root . '/4fach/katgoedt.php'
    ),
    'status-fragment' => (string) file_get_contents(
        $root . '/4fach/vorgaben.php'
    ),
    'read-boundary' => (string) file_get_contents(
        $root . '/app/read_authorization.php'
    ),
    'retired-overview' => (string) file_get_contents(
        $root . '/4fach/all_msg.php'
    ),
    'retired-counter' => (string) file_get_contents(
        $root . '/4fach/counter.php'
    ),
    'retired-status' => (string) file_get_contents(
        $root . '/4fach/status.php'
    ),
];
$assert(
    str_contains($sources['overview'], '"message-overview"')
        && str_contains($sources['overview'], 'estab_read_require_area'),
    'message overview lacks its privileged area gate'
);
$assert(
    str_contains($sources['tracking'], '"tracking"')
        && str_contains($sources['tracking'], 'estab_read_require_area'),
    'tracking lacks its telecommunications area gate'
);
$assert(
    str_contains(
        $sources['messages'],
        'function estab_message_fetch_for_incident_by_id('
    )
        && substr_count(
            $sources['read-boundary'],
            'estab_message_fetch_for_incident_by_id('
        ) >= 2
        && str_contains(
            $sources['read-boundary'],
            "scope['incident']['active_einsatz_id']"
        )
        && str_contains(
            $sources['read-boundary'],
            'estab_attachment_find_for_incident('
        ),
    'object reads re-resolve ambient active incident after authorization'
);
$assert(
    str_contains(
        $sources['forms'],
        'estab_read_filter_generated_forms_for_incident'
    )
        && str_contains(
            $sources['forms'],
            'estab_generated_form_list_for_incident'
        )
        && str_contains($sources['download'], 'estab_read_message_allowed'),
    'generated forms are not authorized through their message object'
);
$assert(
    str_contains($sources['download'], 'estab_read_attachment(')
        && str_contains($sources['preview'], 'estab_read_attachment (')
        && str_contains(
            $sources['attachments'],
            'estab_read_filter_attachments_for_incident'
        )
        && str_contains(
            $sources['attachments'],
            'estab_attachment_list_for_incident'
        )
        && str_contains($sources['attachments'], 'estab_read_attachment ('),
    'attachment list/download/preview/selection do not share one policy'
);
$assert(
    str_contains(
        $sources['messages'],
        'Message attachment authorizer is required'
    )
        && str_contains(
            $sources['message-handler'],
            'estab_read_require_attachment_use_scope'
        ),
    'final message mutation can bypass attachment-use authorization'
);
$assert(
    str_contains(
        $sources['read-boundary'],
        'function estab_read_require_operational_scope('
    )
        && str_contains(
            $sources['read-boundary'],
            'WHERE assignment.`dienstbesetzung_id` = ?'
        )
        && str_contains(
            $sources['read-boundary'],
            "AND duty_shift.`status` = 'AKTIV'"
        )
        && str_contains(
            $sources['read-boundary'],
            "AND assignment.`status` = 'ANGENOMMEN'"
        )
        && str_contains(
            $sources['read-boundary'],
            'AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
        )
        && str_contains(
            $sources['read-boundary'],
            'AND BINARY assignment.`funktion` = BINARY ?'
        )
        && str_contains(
            $sources['read-boundary'],
            'AND BINARY assignment.`rolle` = BINARY ?'
        ),
    'central reads are not bound to the exact selected active assignment'
);
$assert(
    str_contains(
        $sources['message-controller'],
        'estab_read_require_operational_scope ('
    )
        && str_contains(
            $sources['message-controller'],
            'estab_read_message_allowed ('
        )
        && str_contains(
            $sources['message-list'],
            'estab_read_filter_messages ('
        )
        && str_contains(
            $sources['message-list'],
            'estab_message_staff_access_sql'
        ),
    'message controller/list can expose an object outside the selected scope'
);
$assert(
    str_contains(
        $sources['sidebar'],
        'estab_message_staff_access_sql'
    )
        && str_contains(
            $sources['sidebar'],
            "\$assignment = \$identity['duty_assignment_id'] ?? null;"
        )
        && str_contains(
            $sources['sidebar'],
            "preg_match('/\\A[1-9][0-9]{0,18}\\z/D', \$assignment)"
        ),
    'sidebar queues/actions are not exact-recipient and selected-hat scoped'
);
$commandPostScope = strpos(
    $sources['command-post'],
    'if (is_array($selectedIdentity)) {'
);
$telecomRead = strpos(
    $sources['command-post'],
    '$plans = estab_dv_telecom_plans('
);
$messengerRead = strpos(
    $sources['command-post'],
    '$jobs = estab_dv_messenger_jobs('
);
$assert(
    str_contains(
        $sources['command-post'],
        'estab_dv_user_handover_requests('
    )
        && str_contains(
            $sources['command-post'],
            'estab_dv_active_shift_summary('
        )
        && !str_contains(
            $sources['command-post'],
            'estab_dv_shift_list('
        )
        && str_contains(
            $sources['command-post'],
            'estab_read_require_operational_scope('
        )
        && str_contains(
            $sources['command-post'],
            'data-estab-duty-selection-required'
        )
        && is_int($commandPostScope)
        && is_int($telecomRead)
        && is_int($messengerRead)
        && $commandPostScope < $telecomRead
        && $commandPostScope < $messengerRead,
    'command-post bootstrap exposes unrelated operational data before hat selection'
);
foreach (['ETB' => $sources['etb'], 'TBB' => $sources['tbb']] as $name => $source) {
    $gate = strpos($source, 'estab_read_require_operational_scope (');
    $constructor = strpos($source, 'new ' . strtolower($name) . '_liste');
    $assert(
        str_contains(
            $source,
            'require_once __DIR__ . "/../app/read_authorization.php";'
        )
            && str_contains(
                $source,
                'estab_read_session_identity ($_SESSION)'
            )
            && is_int($gate)
            && is_int($constructor)
            && $gate < $constructor,
        "{$name} reads entries before validating the selected active hat"
    );
}
$assert(
    str_contains(
        $sources['categories'],
        'estab_read_require_operational_scope('
    )
        && substr_count(
            $sources['categories'],
            'estab_read_message('
        ) >= 3,
    'category GET/POST actions are not bound to the selected message object'
);
$assert(
    str_contains(
        $sources['status-fragment'],
        'estab_read_require_operational_scope('
    )
        && str_contains(
            $sources['status-fragment'],
            'data-estab-duty-selection-required'
        ),
    'live sidebar status is exposed before selecting a duty assignment'
);
$assert(
    str_contains($sources['retired-overview'], 'http_response_code(410)')
        && strpos($sources['retired-overview'], 'exit;')
            < strpos($sources['retired-overview'], 'include (')
        && str_contains($sources['retired-counter'], 'http_response_code(410)')
        && strpos($sources['retired-counter'], 'exit;')
            < strpos($sources['retired-counter'], 'session_start')
        && str_contains($sources['retired-status'], 'http_response_code(410)')
        && strpos($sources['retired-status'], 'exit;')
            < strpos($sources['retired-status'], 'session_start'),
    'retired global read endpoints do not fail closed before legacy execution'
);

echo 'read authorization security: OK (' . $assertions
    . " assertions)\n";
