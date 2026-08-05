<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/logbook.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$validTitle = estab_logbook_validate_title([
    'einsatz' => 'Hochwasser Süd',
    'ort' => 'Kreisgebiet & Innenstadt',
]);
$assert($validTitle['valid'] === true, 'valid Unicode title rejected');
$assert(
    estab_logbook_validate_title(['einsatz' => '', 'ort' => 'Test'])['valid'] === false,
    'empty operation title accepted'
);
$assert(
    estab_logbook_validate_title([
        'einsatz' => str_repeat('x', ESTAB_LOGBOOK_TITLE_MAX_LENGTH + 1),
        'ort' => 'Test',
    ])['valid'] === false,
    'overlong operation title accepted'
);
$assert(
    estab_logbook_validate_title(['einsatz' => "Test\nInjected", 'ort' => 'Test'])['valid'] === false,
    'control character in single-line title accepted'
);

$validEntry = estab_logbook_validate_entry([
    'event' => "Lageänderung\nzweite Zeile <script>",
    'comment' => 'Rückmeldung & Prüfung',
]);
$assert($validEntry['valid'] === true, 'valid multiline entry rejected');
$assert(
    estab_logbook_validate_entry(['event' => '', 'comment' => ''])[ 'valid'] === false,
    'empty event accepted'
);
$assert(
    estab_logbook_validate_entry([
        'event' => str_repeat('x', ESTAB_LOGBOOK_TEXT_MAX_LENGTH + 1),
        'comment' => '',
    ])['valid'] === false,
    'overlong event accepted'
);
$assert(
    estab_logbook_validate_entry(['event' => ['nested'], 'comment' => ''])['valid'] === false,
    'non-scalar event accepted'
);
$assignedEntry = estab_logbook_validate_entry([
    'event' => 'Aufgabe für S3',
    'comment' => '',
    'assignee_assignment_id' => '17',
]);
$assert(
    $assignedEntry['valid'] === true
        && $assignedEntry['data']['assignee_assignment_id'] === 17
        && estab_logbook_validate_entry([
            'event' => 'Ungültige Zuordnung',
            'assignee_assignment_id' => '0',
        ])['valid'] === false
        && estab_logbook_validate_entry([
            'entry_type' => 'betriebsereignis',
            'operations' => 'TTB ohne ETB-Zuordnung',
            'assignee_assignment_id' => '17',
        ], 'tbb')['valid'] === false,
    'optional ETB assignment validation is incomplete'
);
$referencedEntry = estab_logbook_validate_entry([
    'event' => 'Folgeeintrag',
    'reference' => '17',
]);
$assert(
    $referencedEntry['valid'] === true
        && $referencedEntry['data']['reference'] === '17'
        && estab_logbook_validate_entry([
            'event' => 'Freitextbezug',
            'reference' => 'Lagekarte Nord',
        ])['valid'] === false
        && estab_logbook_validate_entry([
            'event' => 'Nicht kanonisch',
            'reference' => '017',
        ])['valid'] === false
        && estab_logbook_validate_entry([
            'event' => 'Zu große Nummer',
            'reference' => '4294967296',
        ])['valid'] === false,
    'new ETB references are not canonical local book numbers'
);
$referenceRows = [
    ['estab_book_lfd' => 1, 'estab_reference' => null],
    ['estab_book_lfd' => 2, 'estab_reference' => '1'],
    ['estab_book_lfd' => 3, 'estab_reference' => '1'],
    ['estab_book_lfd' => 4, 'estab_reference' => '2'],
    ['estab_book_lfd' => 5, 'estab_reference' => 'Bestandsakte 3'],
];
$forwardReference = estab_logbook_etb_reference_graph(
    $referenceRows,
    1,
    'forward',
    1
);
$backwardReference = estab_logbook_etb_reference_graph(
    $referenceRows,
    4,
    'backward',
    5
);
$assert(
    array_column($forwardReference['rows'], 'estab_book_lfd') === [1, 2, 3]
        && $forwardReference['truncated'] === true
        && array_column(
            $backwardReference['rows'],
            'estab_book_lfd'
        ) === [4, 2, 1]
        && $backwardReference['truncated'] === false,
    'bounded branched ETB reference traversal is incorrect'
);
$graphRejectedMissing = false;
try {
    estab_logbook_etb_reference_graph($referenceRows, 99, 'forward', 5);
} catch (EstabIncidentConflictException) {
    $graphRejectedMissing = true;
}
$assert(
    $graphRejectedMissing
        && estab_logbook_stored_etb_reference_number('Bestandsakte 3') === null,
    'ETB reference evaluation accepted a missing target or inferred legacy text'
);
$assert(
    estab_logbook_assignment_snapshot([
        'funktion' => 'S3',
        'rolle' => 'Stab',
        'benutzer' => 'Beispiel, Erika',
        'benutzer_kuerzel' => 'bei',
    ]) === 'S3 (Stab): Beispiel, Erika [bei]',
    'immutable ETB assignment snapshot is not deterministic'
);
$assert(
    array_keys(estab_logbook_entry_types())
        === ['ohne', 'A', 'B', 'E', 'K', 'W', 'korrektur']
        && estab_logbook_normalize_etb_type('auftrag') === 'B'
        && estab_logbook_normalize_etb_type('entscheidung') === 'ohne',
    'official ETB classifications or legacy normalization are incomplete'
);
$assert(
    (estab_logbook_ttb_entry_types()['nachricht'] ?? null)
        === 'Nachricht von / an'
        && !array_key_exists(
            'nachricht',
            estab_logbook_ttb_manual_entry_types()
        )
        && array_keys(estab_logbook_ttb_manual_entry_types()) === [
            'betrieb_personal',
            'kanal',
            'betriebsereignis',
            'quittung',
            'korrektur',
        ],
    'manual TTB types expose the reserved message-workflow classification '
        . 'or historic message labels are no longer readable'
);
$assert(
    estab_logbook_etb_attachment_number(12, 17) === 'ETB 12-17-1'
        && estab_logbook_parse_etb_attachment_number('ETB 12-17-1') === [
            'incident_id' => 12,
            'entry_number' => 17,
            'unit_number' => 1,
        ]
        && estab_logbook_parse_etb_attachment_number('12-17-1') !== null
        && estab_logbook_parse_etb_attachment_number('EL0001') === null,
    'ETB attachment numbering is not stable or parseable'
);
$validTbb = estab_logbook_validate_entry([
    'entry_type' => 'kanal',
    'event_time' => '2026-07-31T12:34',
    'personnel_duty' => 'Fernmelder-Beispiel im Dienst',
    'channel' => 'Rufgruppe THW 1',
    'message_route' => 'Leitstelle an Führungsstelle',
    'operations' => 'Nachricht aufgenommen',
    'receipt' => 'Quittung AB1234',
], 'tbb');
$assert(
    $validTbb['valid'] === true
        && $validTbb['data']['event_type'] === 'kanal'
        && $validTbb['data']['event_time'] === '2026-07-31 12:34:00'
        && $validTbb['data']['message_id'] === null
        && str_contains(
            (string) $validTbb['data']['event'],
            'Nachricht von / an: Leitstelle an Führungsstelle'
        ),
    'official structured TTB entry did not validate or retain its evidence'
);
$assert(
    estab_logbook_validate_entry([
        'entry_type' => 'nachricht',
        'event_time' => '2026-07-31T12:34',
        'message_route' => 'Leitstelle an Führungsstelle',
    ], 'tbb')['valid'] === false,
    'manual unlinked TTB message evidence was accepted'
);
$assert(
    estab_logbook_validate_entry([
        'entry_type' => 'nachricht',
        'event_time' => '2026-07-31T12:34',
        'message_route' => 'Leitstelle an Führungsstelle',
        'message_id' => '42',
    ], 'tbb')['valid'] === false,
    'manual TTB entry claimed the canonical internal message link'
);
$assert(
    estab_logbook_validate_entry([
        'entry_type' => 'betriebsereignis',
        'event_time' => '2026-07-31T12:34',
    ], 'tbb')['valid'] === false,
    'empty structured TTB entry was accepted'
);
$assert(
    estab_logbook_validate_entry([
        'entry_type' => 'korrektur',
        'event_time' => '2026-07-31T12:34',
        'operations' => 'Berichtigter Nachweis',
        'correction_of' => '1',
        'comment' => '',
    ], 'tbb')['valid'] === false,
    'TTB correction without a reason was accepted'
);
$writerRoster = [[
    'dienstbesetzung_id' => 1,
    'benutzer_kuerzel' => 's2a',
    'benutzer' => 'S2 Beispiel',
    'funktion' => 'S2',
    'rolle' => 'Stab',
], [
    'dienstbesetzung_id' => 2,
    'benutzer_kuerzel' => 'etb1',
    'benutzer' => 'ETB Beispiel',
    'funktion' => 'ETB',
    'rolle' => 'Stab',
], [
    'dienstbesetzung_id' => 4,
    'benutzer_kuerzel' => 'aw2',
    'benutzer' => 'Fernmelder Zwei',
    'funktion' => 'A/W',
    'rolle' => 'Fernmelder',
], [
    'dienstbesetzung_id' => 3,
    'benutzer_kuerzel' => 'aw1',
    'benutzer' => 'Fernmelder Eins',
    'funktion' => 'A/W',
    'rolle' => 'Fernmelder',
]];
$assert(
    str_contains(
        estab_logbook_lifecycle_writer_text($writerRoster, 'etb'),
        'ETB Beispiel [etb1]'
    )
        && str_contains(
            estab_logbook_lifecycle_writer_text($writerRoster, 'tbb'),
            'Fernmelder Eins [aw1]'
        ),
    'historical ETB/TBB roster summaries are not deterministic'
);
$rawRosterText = estab_logbook_lifecycle_roster_text($writerRoster);
$assert(
    str_contains($rawRosterText, 'A/W (Fernmelder): Fernmelder Eins [aw1]')
        && str_contains(
            estab_function_display_text($rawRosterText),
            'Fernmelder: Fernmelder Eins [aw1]'
        )
        && !str_contains(
            estab_function_display_text($rawRosterText),
            'A/W (Fernmelder)'
        ),
    'canonical roster evidence is not separated from its display label'
);
$assert(
    estab_auth_html('<script>alert("x")</script>')
        === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
    'logbook HTML boundary does not escape active markup'
);

$root = dirname(__DIR__, 2);
$helper = file_get_contents($root . '/app/logbook.php');
$lifecycle = file_get_contents($root . '/app/logbook_lifecycle.php');
$messageRepository = file_get_contents($root . '/app/message_repository.php');
$incidentDomain = file_get_contents($root . '/app/incident.php');
$incidentPdf = file_get_contents($root . '/app/incident_pdf.php');
$operationsDomain = file_get_contents($root . '/app/dv_operations.php');
$etb = file_get_contents($root . '/stabetb/etb.php');
$tbb = file_get_contents($root . '/fmtbb/tbb.php');
$httpSmoke = file_get_contents($root . '/tests/integration/http_smoke.sh');
$logbooksHttp = file_get_contents($root . '/tests/integration/logbooks_http.sh');
$categoriesHttp = file_get_contents($root . '/tests/integration/categories_http.sh');
$legacyLoginHttp = file_get_contents($root . '/tests/integration/legacy_login_http.sh');
$messageWorkflowHttp = file_get_contents(
    $root . '/tests/integration/message_workflow_http.sh'
);
if (
    !is_string($helper)
    || !is_string($lifecycle)
    || !is_string($messageRepository)
    || !is_string($incidentDomain)
    || !is_string($incidentPdf)
    || !is_string($operationsDomain)
    || !is_string($etb)
    || !is_string($tbb)
    || !is_string($httpSmoke)
    || !is_string($logbooksHttp)
    || !is_string($categoriesHttp)
    || !is_string($legacyLoginHttp)
    || !is_string($messageWorkflowHttp)
) {
    throw new RuntimeException('Could not read logbook source files');
}

$fixtureDefault = static function (
    string $source,
    string $variable,
    string $environment
): string {
    $pattern = '/^'
        . preg_quote($variable, '/')
        . '=\\$\\{'
        . preg_quote($environment, '/')
        . ':-([^}\\r\\n]+)\\}$/m';
    if (preg_match($pattern, $source, $matches) !== 1) {
        throw new RuntimeException(
            "Missing integration fixture {$variable}/{$environment}"
        );
    }

    return $matches[1];
};
$assert(
    $fixtureDefault(
        $httpSmoke,
        'legacy_registration_code',
        'ESTAB_TEST_TBB_CODE'
    ) === $fixtureDefault($logbooksHttp, 'aw_code', 'ESTAB_TEST_TBB_CODE')
        && $fixtureDefault(
            $httpSmoke,
            'legacy_registration_name',
            'ESTAB_TEST_TBB_NAME'
        ) === $fixtureDefault(
            $logbooksHttp,
            'aw_name',
            'ESTAB_TEST_TBB_NAME'
        ),
    'HTTP smoke and logbook integration assign different identities '
        . 'to their shared A/W account'
);
$sharedS1Defaults = [];
foreach (
    [
        [$httpSmoke, 'test'],
        [$categoriesHttp, 's1'],
        [$legacyLoginHttp, 'test'],
    ] as [$source, $prefix]
) {
    $sharedS1Defaults[] = implode('|', [
        $fixtureDefault(
            $source,
            $prefix . '_name',
            'ESTAB_TEST_LOGIN_NAME'
        ),
        $fixtureDefault(
            $source,
            $prefix . '_code',
            'ESTAB_TEST_LOGIN_CODE'
        ),
        $fixtureDefault(
            $source,
            $prefix . '_function',
            'ESTAB_TEST_LOGIN_FUNCTION'
        ),
    ]);
}
$assert(
    count(array_unique($sharedS1Defaults)) === 1,
    'HTTP, category, and legacy-login integrations assign different '
        . 'identities to their shared S1 account'
);
$assert(
    $fixtureDefault(
        $httpSmoke,
        'account_s2_name',
        'ESTAB_TEST_ETB_NAME'
    ) === $fixtureDefault(
        $logbooksHttp,
        's2_name',
        'ESTAB_TEST_ETB_NAME'
    )
        && $fixtureDefault(
            $httpSmoke,
            'account_s2_code',
            'ESTAB_TEST_ETB_CODE'
        ) === $fixtureDefault(
            $logbooksHttp,
            's2_code',
            'ESTAB_TEST_ETB_CODE'
        )
        && $fixtureDefault(
            $logbooksHttp,
            's2_name',
            'ESTAB_TEST_ETB_NAME'
        ) === $fixtureDefault(
            $categoriesHttp,
            's2_name',
            'ESTAB_TEST_ETB_NAME'
        )
        && $fixtureDefault(
            $logbooksHttp,
            's2_code',
            'ESTAB_TEST_ETB_CODE'
        ) === $fixtureDefault(
            $categoriesHttp,
            's2_code',
            'ESTAB_TEST_ETB_CODE'
        ),
    'HTTP, logbook, and category integrations assign different identities '
        . 'to their shared S2 account'
);
$assert(
    $fixtureDefault(
        $httpSmoke,
        'account_si_name',
        'ESTAB_TEST_CATEGORY_SI_NAME'
    ) === $fixtureDefault(
        $categoriesHttp,
        'si_name',
        'ESTAB_TEST_CATEGORY_SI_NAME'
    )
        && $fixtureDefault(
            $httpSmoke,
            'account_si_code',
            'ESTAB_TEST_CATEGORY_SI_CODE'
        ) === $fixtureDefault(
            $categoriesHttp,
            'si_code',
            'ESTAB_TEST_CATEGORY_SI_CODE'
        ),
    'HTTP and category integrations assign different identities '
        . 'to their shared Si account'
);

$derivedWorkflowPrefixes = [];
foreach (['aw', 'ldf', 'si', 's1', 's2', 's3', 's6', 'pol'] as $function) {
    $pattern = '/^' . preg_quote($function, '/')
        . '_code="([a-z])\\$\\{identity_seed\\}"$/m';
    if (preg_match($pattern, $messageWorkflowHttp, $matches) !== 1) {
        throw new RuntimeException(
            "Missing isolated workflow fixture for {$function}"
        );
    }
    $derivedWorkflowPrefixes[] = $matches[1];
}
$assert(
    count(array_unique($derivedWorkflowPrefixes))
        === count($derivedWorkflowPrefixes)
        && !in_array('e', $derivedWorkflowPrefixes, true),
    'Derived message-workflow identities can collide with each other or '
        . 'with the fixed e2* integration accounts'
);

$assert(
    substr_count($helper, '$connection->prepare(') >= 3
        && substr_count($helper, '->bind_param(') >= 3,
    'logbook database values are not consistently parameterized'
);
$insertStart = strpos($helper, 'function estab_logbook_insert_entry(');
$insertEnd = is_int($insertStart)
    ? strpos(
        $helper,
        'function estab_logbook_redcopy_function(',
        $insertStart
    )
    : false;
$insertSource = is_int($insertStart)
    && is_int($insertEnd)
    && $insertEnd > $insertStart
    ? substr($helper, $insertStart, $insertEnd - $insertStart)
    : '';
$writerResolutionPosition = strpos(
    $insertSource,
    '$writerIdentity = estab_dv_require_write_capability('
);
$writerContextPosition = strpos(
    $insertSource,
    'estab_logbook_manual_writer_context('
);
$writerPersistencePosition = strpos(
    $insertSource,
    "\$function = (string) (\$writerIdentity['funktion'] ?? '');"
);
$assert(
    $insertSource !== ''
        && is_int($writerResolutionPosition)
        && is_int($writerContextPosition)
        && is_int($writerPersistencePosition)
        && $writerResolutionPosition < $writerContextPosition
        && $writerContextPosition < $writerPersistencePosition
        && preg_match(
            '/estab_logbook_manual_writer_context\(\s*'
                . '\$connection,\s*\$incidentId,\s*'
                . '\$writerIdentity,\s*\$kind\s*\)/s',
            $insertSource
        ) === 1
        && str_contains(
            $insertSource,
            "\$code = (string) (\$writerIdentity['kuerzel'] ?? '');"
        )
        && str_contains(
            $insertSource,
            "\$user = (string) (\$writerIdentity['benutzer'] ?? '');"
        )
        && !str_contains(
            $insertSource,
            "\$function = (string) (\$identity['funktion'] ?? '');"
        ),
    'logbook insert discards the exact STRICT/LOOSE function that authorized the write'
);
$assert(
    str_contains($helper, "WHERE `mtx_rc2` IN ('t', '1')"),
    'ETB red-copy lookup does not support current and historical flags'
);
$assert(
    str_contains($helper, 'ESTAB_LOGBOOK_TITLE_MAX_LENGTH = 255')
        && str_contains($helper, 'ESTAB_LOGBOOK_TEXT_MAX_LENGTH = 10000'),
    'server-side logbook length limits are missing'
);
$assert(
    str_contains($helper, 'estab_auth_table($table)')
        && str_contains($helper, "in_array(\$kind, ['etb', 'tbb'], true)"),
    'dynamic table or column identifiers are not allowlisted'
);
$assert(
    str_contains($helper, 'estab_csrf_require_post($server, $post)'),
    'shared logbook CSRF gate is missing'
);
$assert(
    str_contains($helper, "preg_match('~\\A/[A-Za-z0-9_./-]+\\z~D', \$path) === 1")
        && preg_match('~\A/[A-Za-z0-9_./-]+\z~D', '/stabetb/etb.php') === 1,
    'same-origin logbook redirects reject valid application paths'
);
$assert(
    str_contains($helper, 'function estab_logbook_validate_references(')
        && str_contains($helper, 'function estab_logbook_etb_reference_target(')
        && str_contains($helper, 'FROM `nv_nachrichten`')
        && str_contains($helper, 'FROM `nv_anhang`')
        && str_contains($helper, '`estab_correction_of`')
        && substr_count($helper, 'LIMIT 1 FOR UPDATE') >= 3,
    'ETB references are not locked and checked against the active incident'
);
$assert(
    str_contains($helper, 'function estab_logbook_available_etb_attachments(')
        && str_contains($helper, 'attachment_row.`status` = 1')
        && str_contains($helper, 'AND NOT EXISTS (')
        && str_contains($helper, 'Der Anhang besitzt bereits eine ETB-Anlagennummer')
        && str_contains($helper, "(int) (\$attachment['status'] ?? 0) !== 1")
        && str_contains($helper, 'estab_logbook_parse_etb_attachment_number(')
        && str_contains($helper, 'attachment_row.`org_filename` LIKE ?'),
    'ETB attachments are not finalized, unique, selectable, or searchable'
);
$assert(
    str_contains($helper, 'function estab_logbook_manual_writer_context(')
        && str_contains($helper, 'function estab_logbook_is_designated_writer(')
        && str_contains(
            $helper,
            'estab_incident_duty_shift_required($incident)'
        )
        && str_contains($helper, "duty_shift.`status` = 'AKTIV'")
        && str_contains($helper, "assignment.`status` = 'ANGENOMMEN'")
        && str_contains($helper, "\$identity['duty_assignment_id'] ?? null")
        && str_contains(
            $helper,
            'estab_logbook_designated_writer_assignment('
        )
        && str_contains($helper, "'shift_id' => null")
        && str_contains($helper, "'writer_assignment_id' => null")
        && str_contains(
            $helper,
            "estab_auth_identity_has_function(\$selected, 'ETB', 'Stab')"
        )
        && str_contains(
            $helper,
            "estab_auth_identity_has_function(\$selected, 'S2', 'Stab')"
        )
        && str_contains($helper, "'A/W'")
        && str_contains($helper, "'Fernmelder'")
        && str_contains($helper, "\$orderSql = '`estab_book_lfd` DESC';")
        && str_contains($helper, '`estab_personnel_duty`')
        && str_contains($helper, '`estab_message_route`')
        && str_contains($helper, '`estab_receipt`'),
    'STRICT/LOOSE writer rule, incident-local ordering, or official TTB fields are missing'
);
$assert(
    str_contains($helper, 'function estab_logbook_ttb_manual_entry_types(): array')
        && str_contains($helper, "unset(\$types['nachricht']);")
        && str_contains($tbb, 'estab_logbook_ttb_manual_entry_types ()')
        && str_contains($tbb, 'das TBB auch ohne Anlagen in ')
        && str_contains($tbb, 'Grundzügen verständlich bleibt.'),
    'manual TTB UI or validation exposes message evidence or omits the '
        . 'standalone-comprehensibility instruction'
);
$assert(
    str_contains($helper, 'function estab_logbook_manual_writer_context(')
        && str_contains($helper, 'function estab_logbook_etb_assignee_context(')
        && str_contains($helper, 'function estab_logbook_active_assignment_options(')
        && str_contains($helper, 'estab_logbook_assignment_snapshot($row)')
        && str_contains($helper, '$strictMode = estab_incident_duty_shift_required($incident)')
        && str_contains($helper, "assignment.`status` <> 'ZURUECKGEZOGEN'")
        && str_contains($helper, "duty_shift.`status` = 'AKTIV'")
        && str_contains($helper, "assignment.`status` = 'ANGENOMMEN'")
        && str_contains($helper, 'LIMIT 1 FOR UPDATE')
        && str_contains($helper, '`estab_shift_id`')
        && str_contains($helper, '`estab_writer_assignment_id`')
        && str_contains($helper, '`estab_assignee_assignment_id`')
        && str_contains($helper, '`estab_assignment`'),
    'manual logbook rows do not separate STRICT active assignments from LOOSE historical metadata'
);
$assignmentOptionsStart = strpos(
    $helper,
    'function estab_logbook_active_assignment_options('
);
$manualWriterStart = strpos(
    $helper,
    'function estab_logbook_manual_writer_context('
);
$assignmentOptionsSource = is_int($assignmentOptionsStart)
    && is_int($manualWriterStart)
    ? substr(
        $helper,
        $assignmentOptionsStart,
        $manualWriterStart - $assignmentOptionsStart
    )
    : '';
$manualWriterEnd = strpos(
    $helper,
    'function estab_logbook_etb_assignee_context(',
    is_int($manualWriterStart) ? $manualWriterStart : 0
);
$manualWriterSource = is_int($manualWriterStart) && is_int($manualWriterEnd)
    ? substr($helper, $manualWriterStart, $manualWriterEnd - $manualWriterStart)
    : '';
$assigneeStart = $manualWriterEnd;
$assigneeEnd = strpos(
    $helper,
    'function estab_logbook_is_designated_writer(',
    is_int($assigneeStart) ? $assigneeStart : 0
);
$assigneeSource = is_int($assigneeStart) && is_int($assigneeEnd)
    ? substr($helper, $assigneeStart, $assigneeEnd - $assigneeStart)
    : '';
$assert(
    str_contains(
        $manualWriterSource,
        'estab_logbook_is_designated_writer('
    )
        && str_contains(
            $manualWriterSource,
            'estab_incident_duty_shift_required($incident)'
        )
        && str_contains($manualWriterSource, 'duty_assignment_id')
        && str_contains($manualWriterSource, '`nv_dienstbesetzungen`')
        && str_contains($manualWriterSource, "duty_shift.`status` = 'AKTIV'")
        && str_contains($manualWriterSource, "assignment.`status` = 'ANGENOMMEN'")
        && str_contains($manualWriterSource, "'shift_id' => null")
        && str_contains(
            $manualWriterSource,
            "'writer_assignment_id' => null"
        )
        && str_contains(
            $assignmentOptionsSource,
            '$strictMode = estab_incident_duty_shift_required($incident)'
        )
        && str_contains($assignmentOptionsSource, "duty_shift.`status` = 'AKTIV'")
        && str_contains($assignmentOptionsSource, "assignment.`status` = 'ANGENOMMEN'")
        && str_contains(
            $assignmentOptionsSource,
            "assignment.`status` <> 'ZURUECKGEZOGEN'"
        )
        && str_contains($assigneeSource, '$strictMode')
        && str_contains($assigneeSource, "duty_shift.`status` = 'AKTIV'")
        && str_contains($assigneeSource, "assignment.`status` = 'ANGENOMMEN'")
        && str_contains($assigneeSource, "assignment.`status` <> 'ZURUECKGEZOGEN'")
        && str_contains($assigneeSource, 'duty_shift.`einsatz_id` = ?'),
    'STRICT writer/assignee or LOOSE historical assignment policy is unsafe'
);
$assert(
    str_contains($lifecycle, 'function estab_logbook_lifecycle_open_books(')
        && str_contains(
            $lifecycle,
            'function estab_logbook_lifecycle_open_books_if_empty('
        )
        && str_contains($lifecycle, 'function estab_logbook_lifecycle_handover(')
        && str_contains($lifecycle, 'function estab_logbook_lifecycle_close_books(')
        && str_contains($lifecycle, 'function estab_logbook_lifecycle_message_transport(')
        && str_contains(
            $operationsDomain,
            'estab_logbook_lifecycle_open_books('
        )
        && !str_contains(
            $operationsDomain,
            'estab_logbook_lifecycle_open_books_if_empty('
        )
        && str_contains(
            $incidentDomain,
            'estab_logbook_lifecycle_open_books_if_empty('
        )
        && str_contains($operationsDomain, 'estab_logbook_lifecycle_handover(')
        && str_contains($incidentDomain, 'estab_logbook_lifecycle_close_books(')
        && str_contains($lifecycle, ". 'Einsatzbeginn: ' . \$begin")
        && str_contains($lifecycle, 'string $handedOverAt')
        && str_contains($lifecycle, 'string $takenOverAt')
        && str_contains($lifecycle, 'Persönlich übergeben von')
        && str_contains($lifecycle, 'persönlich übernommen von')
        && str_contains(
            $lifecycle,
            "BINARY `estab_entry_type` = BINARY 'nachricht'"
        )
        && !str_contains(
            $lifecycle,
            "`estab_entry_type` = 'nachricht'"
        )
        && str_contains($operationsDomain, 'function estab_dv_database_now(')
        && str_contains($operationsDomain, "'handed_over_at' => \$initiatedAt")
        && str_contains($operationsDomain, "'taken_over_at' => \$confirmedAt")
        && substr_count(
            $messageRepository,
            'estab_logbook_lifecycle_message_transport('
        ) === 2,
    'automatic opening, handover, close, or message-transport evidence is not wired'
);
$assert(
    substr_count(
        $lifecycle,
        '// Preserve LAST_INSERT_ID before the marker cleanup query.'
    ) === 2
        && substr_count(
            $lifecycle,
            'return estab_logbook_lifecycle_with_system_write_context('
        ) >= 2
        && substr_count(
            $lifecycle,
            'return (int) $connection->insert_id;'
        ) >= 2,
    'automatic ETB/TBB inserts lose their generated ID during marker cleanup'
);

foreach (['ETB' => $etb, 'TBB' => $tbb] as $name => $source) {
    $prefix = strtolower($name);
    $normalizedMarkupSource = str_replace('\\"', '"', $source);
    $assert(
        preg_match(
            '/<form\b[^>]*\bmethod="post"/i',
            $normalizedMarkupSource
        ) === 1
            && str_contains($source, 'estab_csrf_field ()')
            && str_contains($source, 'estab_logbook_require_csrf ($_SERVER, $_POST)'),
        "{$name} writes are not POST-only and CSRF-protected"
    );
    $assert(
        str_contains($source, 'estab_auth_html (')
            && str_contains($source, 'maxlength=\"10000\"'),
        "{$name} output escaping or browser-side length boundary is missing"
    );
    $assert(
        !str_contains($source, 'query_table_iu')
            && !str_contains($source, 'speichen_' . strtolower($name) . '_eintrag ($_GET)'),
        "{$name} still exposes a legacy interpolated GET write path"
    );
    $assert(
        str_contains($source, 'estab_logbook_abort (403'),
        "{$name} role/CSRF failures do not return HTTP 403"
    );
    $assert(
        str_contains(
            $source,
            'require_once __DIR__ . "/../app/read_authorization.php";'
        )
            && str_contains(
                $source,
                'estab_navigation_require_session ($_SESSION'
            )
            && str_contains(
                $source,
                'estab_read_session_identity ($_SESSION)'
            )
            && str_contains(
                $source,
                'estab_read_require_operational_scope ('
            )
            && str_contains(
                $source,
                'estab_dv_has_write_capability ('
            )
            && str_contains(
                $source,
                'estab_logbook_is_designated_writer ('
            )
            && str_contains($source, 'if ($requestMethod === "POST")')
            && str_contains($source, 'if (!$berechtigt)')
            && str_contains(
                $source,
                'estab_navigation_require_selected_duty'
            )
            && !str_contains($source, 'estab_navigation_select_duty')
            && str_contains(
                $source,
                '$' . $prefix . 'obj->' . $prefix . '_identity = $identity;'
            )
            && str_contains(
                $source,
                '$this->' . $prefix . '_identity'
            ),
        "{$name} read/write boundary drops the mode-aware duty gate or server-resolved STRICT/LOOSE identity"
    );
    $assert(
        strpos($source, 'estab_read_require_operational_scope (')
            < strpos($source, 'new ' . strtolower($name) . '_liste'),
        "{$name} reads logbook data before checking active incident and account"
    );
    $assert(
        str_contains(
            $source,
            'var $' . $prefix . '_einsatz_aktiv = false;'
        )
            && str_contains(
                $source,
                '$this->' . $prefix . '_einsatz_aktiv = true;'
            )
            && str_contains(
                $source,
                'estab_incident_command_post_name ($incident)'
            )
            && str_contains(
                $source,
                'catch (EstabIncidentConfigurationException) {'
            )
            && str_contains($source, 'data-estab-incident-incomplete')
            && substr_count(
                $source,
                'if ($' . $prefix . 'obj->' . $prefix
                    . '_einsatz_aktiv)'
            ) >= 2,
        "{$name} renders an incomplete active incident as writable or active"
    );
    $assert(
        str_contains(
            $source,
            '$' . $prefix . 'obj->' . $prefix . '_authorized ='
        )
            && str_contains(
                $source,
                '$berechtigt && $' . $prefix . 'obj->' . $prefix
                    . '_titel_gesetzt;'
            )
            && str_contains(
                $source,
                'if ($' . $prefix . 'obj->' . $prefix
                    . '_authorized && $entryFormRequested)'
            )
            && !str_contains(
                $source,
                'if ($berechtigt && $entryFormRequested)'
            ),
        "{$name} offers an entry menu while the active incident is incomplete"
    );
    $assert(
        preg_match(
            '/catch \\(EstabIncidentConfigurationException \\$exception\\) \\{'
                . '.*?estab_logbook_abort \\(\\s*409,\\s*'
                . '"Der aktive Einsatz ist unvollständig\\./s',
            $source
        ) === 1,
        "{$name} maps an incident configuration write failure to HTTP 409"
    );
    $assert(
        str_contains($source, 'var $' . $prefix . '_rolle ;')
            && str_contains(
                $source,
                '$' . $prefix . 'obj->' . $prefix
                    . '_rolle = $identity ["rolle"];'
            )
            && str_contains(
                $source,
                'var $' . $prefix . '_identity = array ();'
            )
            && str_contains(
                $source,
                '$' . $prefix . 'obj->' . $prefix . '_identity = $identity;'
            )
            && str_contains(
                $source,
                'estab_logbook_insert_entry ('
            )
            && str_contains(
                $source,
                '$this->' . $prefix . '_identity'
            ),
        "{$name} drops selected-hat/additional-function provenance before the guard"
    );
    $assert(
        !str_contains($source, '$_POST ["duty_assignment_id"]')
            && !str_contains($source, '$_GET ["duty_assignment_id"]'),
        "{$name} accepts a client-supplied duty assignment"
    );
}

$assert(
    str_contains($etb, 'estab_dv_has_write_capability (')
        && str_contains($etb, '"EINSATZTAGEBUCH"')
        && !str_contains($etb, '$readauth')
        && !str_contains($etb, 'Keine Berechtigung für das Einsatztagebuch'),
    'ETB readers must remain writable only by fixed ETB capability'
);
$assert(
    str_contains($tbb, 'estab_dv_has_write_capability (')
        && str_contains($tbb, '"BEFOERDERUNG"')
        && !str_contains($tbb, '$readonly')
        && !str_contains($tbb, 'Keine Berechtigung für das technische Betriebsbuch'),
    'TBB readers must remain writable only by fixed Beförderung capability'
);
$assert(
    str_contains($etb, 'ETB durchsuchen und filtern')
        && str_contains($etb, 'ETB-Nr. oder Bestandsbezug')
        && str_contains($etb, 'for=\"etb-search-assignment\">Zuordnung</label>')
        && str_contains($etb, 'name=\"zuordnung\"')
        && str_contains($etb, 'Filter zurücksetzen')
        && str_contains($helper, 'entry_row.`etb_aktion` LIKE ?')
        && str_contains($helper, 'entry_row.`estab_reference` LIKE ?')
        && str_contains($helper, 'entry_row.`estab_assignment` LIKE ?')
        && str_contains($helper, 'entry_row.`estab_book_lfd` = ?')
        && str_contains($helper, "'entscheidung'")
        && str_contains($helper, "'auftrag'")
        && str_contains($helper, 'estab_auth_text_length($value)')
        && str_contains($helper, '$length < 0'),
    'ETB full-text, type, number/reference search is incomplete'
);
$assert(
    str_contains($etb, 'Referenz auf ETB-Nr.</label>')
        && str_contains($etb, 'ETB-Referenzen auswerten')
        && str_contains($etb, 'name=\"referenz_start\"')
        && str_contains($etb, 'name=\"referenz_richtung\"')
        && str_contains($etb, 'name=\"referenz_tiefe\"')
        && str_contains($etb, 'Druckansicht öffnen')
        && str_contains($helper, 'function estab_logbook_etb_reference_graph(')
        && str_contains($helper, "['forward', 'backward']")
        && str_contains($helper, 'LIMIT 1 FOR UPDATE'),
    'ETB reference input or interactive bounded evaluation is incomplete'
);
$assert(
    str_contains($etb, 'Zuordnung (optional)</label>')
        && str_contains($etb, '<select id=\"etb-assignee-assignment\"')
        && str_contains($etb, 'name=\"assignee_assignment_id\"')
        && !str_contains(
            $etb,
            '<input id=\"etb-assignee-assignment\"'
        )
        && str_contains($etb, 'estab_logbook_active_assignment_options (')
        && str_contains($etb, 'Zuordnung: ')
        && str_contains(
            $etb,
            'wird nicht in das amtliche PDF-Formblatt übernommen'
        )
        && !str_contains($etb, '$_POST ["estab_shift_id"]')
        && !str_contains($etb, '$_POST ["estab_writer_assignment_id"]'),
    'ETB assignment selector, browser display, or server-owned writer fields are unsafe'
);
$assert(
    substr_count($etb, 'estab_function_display_text (') >= 5
        && substr_count($tbb, 'estab_function_display_text (') >= 2
        && str_contains(
            $incidentPdf,
            'estab_function_display_text(trim((string) $value))'
        ),
    'stored logbook evidence is not presentation-normalized consistently'
);
$assert(
    !str_contains($incidentPdf, 'estab_assignment'),
    'internal ETB workflow assignment leaked into the official PDF form'
);
$assert(
    str_contains($etb, '>ETB-Anlage</label>')
        && str_contains($etb, '<select id=\"etb-attachment-id\"')
        && !str_contains(
            $etb,
            'Anhang-ID</label><input id=\"etb-attachment-id\"'
        )
        && str_contains($etb, 'ETB-Anlagennummer (z. B. ETB 12-17-1)')
        && str_contains($etb, 'auch ohne ')
        && str_contains($etb, 'Öffnen einer Anlage verständlich bleiben')
        && str_contains($etb, 'estab_logbook_etb_attachment_number ('),
    'ETB attachment selection or official number presentation is incomplete'
);
$assert(
    str_contains($etb, '$eventType !== "korrektur"')
        && str_contains($tbb, '$entryType !== "korrektur"')
        && !str_contains($tbb, 'name=\"ttb-message-id\"'),
    'correction rows remain recursively correctable or manual TBB message links leak'
);

echo "logbook security: OK ({$assertions} assertions)\n";
