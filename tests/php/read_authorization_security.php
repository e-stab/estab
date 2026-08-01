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
    string $role
): array => [
    'benutzer' => $function . ' Test',
    'kuerzel' => $code,
    'funktion' => $function,
    'rolle' => $role,
];

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
    'fixed ETB account function is not bound to its narrow database capability'
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
    estab_read_message_allowed(
        $identity('s10001', 'S1', 'Stab'),
        $terminal
    ),
    'a fixed account function unexpectedly requires a duty assignment'
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

$siVisibility = estab_read_message_visibility_sql($si, 'msg');
$awVisibility = estab_read_message_visibility_sql($aw, 'msg');
$ldfVisibility = estab_read_message_visibility_sql($ldf, 'msg');
$staffVisibility = estab_read_message_visibility_sql($s1, 'msg');
$assert(
    str_contains($siVisibility['sql'], "msg.`x00_status` = 4")
        && str_contains($siVisibility['sql'], "'0000-00-00 00:00:00'")
        && $siVisibility['params'] === ['si0001', 'si0001'],
    'pageable Si SQL does not preserve queue, personal mark and legacy date semantics'
);
$assert(
    str_contains($awVisibility['sql'], "msg.`x00_status` = 2")
        && str_contains($awVisibility['sql'], "msg.`06_befwegausw` <> ''")
        && $awVisibility['params'] === ['aw0001', 'aw0001', 'aw0001'],
    'pageable A/W SQL does not preserve queue, lock and personal mark semantics'
);
$assert(
    str_contains($ldfVisibility['sql'], "msg.`x00_status` = 1")
        && str_contains($ldfVisibility['sql'], "msg.`x01_abschluss` = 'f'")
        && $ldfVisibility['params'] === ['ldf001', 'ldf001'],
    'pageable LdF SQL does not preserve disposition and personal mark semantics'
);
$assert(
    str_contains($staffVisibility['sql'], 'msg.`16_empf` REGEXP ?')
        && count($staffVisibility['params']) === 2,
    'pageable staff SQL bypasses the shared exact staff-access predicate'
);
foreach (['msg` OR 1=1 --', '', '1msg'] as $unsafeAlias) {
    try {
        estab_read_message_visibility_sql($si, $unsafeAlias);
        $assert(false, 'unsafe pageable visibility alias accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'unsafe pageable visibility alias rejected');
    }
}
foreach (
    [
        $identity('xx0001', 'S1', 'Fernmelder'),
        $identity('', 'S1', 'Stab'),
    ] as $forbiddenIdentity
) {
    try {
        estab_read_message_visibility_sql($forbiddenIdentity);
        $assert(false, 'forbidden identity received pageable message SQL');
    } catch (EstabReadPermissionException) {
        $assert(true, 'forbidden identity denied pageable message SQL');
    }
}

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
$attachmentVersionRow = [
    'einsatz_id' => 7,
    'filename' => 'EL0001',
    'fileext' => 'pdf',
    'status' => 1,
    'kuerzel' => 's10001',
    'integrity_required' => 1,
    'ingest_sha256' => str_repeat('a', 64),
    'ingest_size' => 42,
    'integrity_captured_at' => '2026-08-01 12:00:00.000000',
    'comment' => 'does not authorize bytes',
];
$attachmentVersion = estab_read_attachment_authorization_version(
    $attachmentVersionRow
);
$assert(
    preg_match('/\A[a-f0-9]{64}\z/D', $attachmentVersion) === 1
        && hash_equals(
            $attachmentVersion,
            estab_read_attachment_authorization_version(array_replace(
                $attachmentVersionRow,
                ['comment' => 'changed presentation metadata']
            ))
        )
        && !hash_equals(
            $attachmentVersion,
            estab_read_attachment_authorization_version(array_replace(
                $attachmentVersionRow,
                ['einsatz_id' => 8]
            ))
        ),
    'private attachment snapshot is not bound to its authorization row'
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
    'overview navigation is not limited to fixed S2/LAGE_DOKUMENTATION'
);
$assert(
    is_array($tracking)
        && !estab_navigation_duty_access_allowed($tracking, $s1)
        && estab_navigation_duty_access_allowed($tracking, $ldf)
        && estab_navigation_duty_access_allowed($tracking, $aw),
    'tracking navigation is not limited to fixed LdF/A-W functions'
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
        $sources['read-boundary'],
        'function estab_read_attachment_authorization_columns(): string'
    )
        && str_contains($sources['read-boundary'], 'return implode(')
        && str_contains($sources['read-boundary'], "'`12_anhang`'")
        && str_contains($sources['read-boundary'], "'`16_empf`'")
        && !str_contains(
            $sources['read-boundary'],
            "'SELECT * FROM ' . estab_auth_table(\$messageTable)"
        ),
    'attachment previews transfer complete message bodies for authorization'
);
$assert(
    str_contains(
        $sources['read-boundary'],
        'function estab_read_attachment_message_map_for_filenames('
    )
        && str_contains(
            $sources['read-boundary'],
            "'LOCATE(?, `12_anhang`) > 0'"
        )
        && str_contains(
            $sources['read-boundary'],
            '$messageMap = estab_read_attachment_message_map_for_filenames('
        )
        && str_contains(
            $sources['read-boundary'],
            '[$requestedFilename]'
        ),
    'single attachment previews still transfer and lock every linked message'
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
    preg_match(
        '/function estab_read_require_attachment_use_scope\(.*?\n\}/s',
        $sources['read-boundary'],
        $attachmentUseScopeMatch
    ) === 1
        && str_contains(
            (string) ($attachmentUseScopeMatch[0] ?? ''),
            'estab_read_attachments('
        )
        && !str_contains(
            (string) ($attachmentUseScopeMatch[0] ?? ''),
            'estab_read_attachment('
        )
        && str_contains(
            $sources['read-boundary'],
            '$messageMap = estab_read_attachment_message_map('
        )
        && str_contains(
            $sources['read-boundary'],
            '$incidentId,' . "\n" . '        $forUpdate'
        ),
    'final attachment-use validation rescans and relocks every incident '
        . 'message once per attachment'
);
$assert(
    str_contains(
        $sources['read-boundary'],
        'function estab_read_require_operational_scope('
    )
        && str_contains(
            $sources['read-boundary'],
            'estab_dv_require_operational_account('
        )
        && str_contains(
            $sources['read-boundary'],
            'estab_incident_require_active($connection)'
        )
        && str_contains(
            $sources['read-boundary'],
            'estab_dv_require_account_capability('
        )
        && !str_contains($sources['read-boundary'], 'duty_assignment_id')
        && !str_contains($sources['read-boundary'], 'nv_dienstbesetzungen')
        && !str_contains($sources['read-boundary'], 'nv_dienstschichten'),
    'central reads do not enforce active incident, fixed account and capability without a formal shift'
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
    'message controller/list can expose an object outside the fixed account scope'
);
$assert(
    str_contains(
        $sources['sidebar'],
        'estab_message_staff_access_sql'
    )
        && !str_contains($sources['sidebar'], 'duty_assignment_id')
        && str_contains($sources['sidebar'], 'data-estab-account-function'),
    'sidebar queues/actions are not exact-recipient and fixed-account scoped'
);
$commandPostScope = strpos(
    $sources['command-post'],
    '$readScope = estab_read_require_operational_scope('
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
            'estab_read_require_operational_scope('
        )
        && str_contains(
            $sources['command-post'],
            'estab_dv_has_write_capability('
        )
        && !str_contains($sources['command-post'], 'duty_assignment_id')
        && !str_contains($sources['command-post'], "'select_hat'")
        && !str_contains($sources['command-post'], "'accept_hat'")
        && is_int($commandPostScope)
        && is_int($telecomRead)
        && is_int($messengerRead)
        && $commandPostScope < $telecomRead
        && $commandPostScope < $messengerRead,
    'command-post bootstrap exposes unrelated data or still requires a formal duty assignment'
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
        "{$name} reads entries before validating active incident and fixed account"
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
        && !str_contains($sources['status-fragment'], 'duty_assignment_id'),
    'live sidebar status is exposed without fixed-account scope or still requires a formal shift'
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
