<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/incident_export.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
    } catch (
        EstabIncidentExportInputException
        | EstabIncidentExportDataException
        | EstabIncidentInputException
    ) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$sections = estab_incident_export_sections([
    'include_etb' => '1',
    'include_ttb' => '1',
    'include_messages' => '1',
    'include_attachments' => '1',
    'include_message_evidence' => '1',
    'include_duty' => '1',
    'include_s6_plans' => '1',
    'include_courier' => '1',
    'include_operations_evidence' => '1',
]);
$assert(
    $sections === [
        'etb',
        'ttb',
        'messages',
        'attachments',
        'message_evidence',
        'duty',
        's6_plans',
        'courier',
        'operations_evidence',
    ],
    'Complete section selection changed'
);
$assert(
    estab_incident_export_sections(['include_etb' => '1']) === ['etb'],
    'Single section selection changed'
);
$throws(
    static fn (): array => estab_incident_export_sections([]),
    'Empty selection was accepted'
);
$throws(
    static fn (): array => estab_incident_export_sections([
        'include_attachments' => '1',
    ]),
    'Attachments without message forms were accepted'
);
$throws(
    static fn (): array => estab_incident_export_sections([
        'include_etb' => ['1'],
    ]),
    'Array-valued checkbox was accepted'
);
$throws(
    static fn (): array => estab_incident_export_sections([
        'include_etb' => 'true',
    ]),
    'Non-canonical checkbox value was accepted'
);

$assert(
    estab_incident_export_logbook_scope('all') === [
        'mode' => 'all',
        'shift_id' => null,
    ],
    'Whole-logbook scope changed'
);
$assert(
    estab_incident_export_logbook_scope('shift:42') === [
        'mode' => 'shift',
        'shift_id' => 42,
    ],
    'Canonical shift scope was not parsed'
);
foreach ([null, '', 'shift:0', 'shift:01', 'shift:-1', 'shift:1x', ['all']]
    as $invalidScope) {
    $throws(
        static fn (): array => estab_incident_export_logbook_scope(
            $invalidScope
        ),
        'Invalid logbook scope was accepted'
    );
}

$assert(
    estab_incident_export_message_attachments('EL0001.pdf;EL0002.txt;')
        === ['EL0001.pdf', 'EL0002.txt'],
    'Message attachments were not parsed'
);
$assert(
    estab_incident_export_message_attachments(
        'EL0001.pdf; EL0001.pdf ;'
    ) === ['EL0001.pdf'],
    'Message attachment duplicates were not removed'
);
$assert(
    estab_incident_export_message_attachments(null) === [],
    'Null attachment field was not treated as empty'
);
$throws(
    static fn (): array => estab_incident_export_message_attachments(
        '../secret.pdf;'
    ),
    'Traversal attachment was accepted'
);
$throws(
    static fn (): array => estab_incident_export_message_attachments(
        'EL0001.exe;'
    ),
    'Forbidden attachment extension was accepted'
);

$filename = estab_incident_export_filename(
    ['einsatz_id' => 12, 'kennung' => 'EL/2026_001'],
    new DateTimeImmutable('2026-07-29 14:15:16')
);
$assert(
    $filename === 'estab-einsatz-12-el-2026-001-20260729-141516.pdf',
    'Portable PDF filename changed'
);
$throws(
    static fn (): string => estab_incident_export_filename([
        'einsatz_id' => 1,
        'kennung' => '../secret',
    ]),
    'Unsafe incident filename was accepted'
);
$embeddedName = estab_incident_export_embedded_name(
    1,
    str_repeat('A', 220) . '.pdf'
);
$assert(
    strlen($embeddedName) <= 181
        && str_starts_with($embeddedName, 'Anlage-0001-')
        && str_ends_with($embeddedName, '.pdf'),
    'Long embedded attachment name was not shortened safely'
);
$assert(
    estab_incident_export_embedded_name(2, 'EL0001.txt')
        !== estab_incident_export_embedded_name(3, 'EL0001.txt'),
    'Embedded attachment names are not position-unique'
);
$throws(
    static fn (): string => estab_incident_export_embedded_name(
        0,
        'EL0001.txt'
    ),
    'Invalid embedded attachment position was accepted'
);
$assert(
    estab_incident_export_etb_attachment_number_map(12, [[
        'estab_book_lfd' => 17,
        'estab_attachment_id' => 4,
    ]]) === [4 => ['ETB 12-17-1']],
    'Incident export did not derive the official ETB attachment number'
);
$throws(
    static fn (): array => estab_incident_export_etb_attachment_number_map(
        12,
        [[
            'estab_book_lfd' => 17,
            'estab_attachment_id' => 4,
        ], [
            'estab_book_lfd' => 18,
            'estab_attachment_id' => 4,
        ]]
    ),
    'Ambiguous repeated ETB attachment link was exported'
);

$integrityFixture = tempnam(sys_get_temp_dir(), 'estab-integrity-');
if (!is_string($integrityFixture)) {
    throw new RuntimeException('Could not create attachment integrity fixture');
}
try {
    $integrityPayload = "ingest-integrity-fixture\n";
    file_put_contents($integrityFixture, $integrityPayload, LOCK_EX);
    $verifiedIntegrity = estab_attachment_integrity_verify_file([
        'integrity_required' => 1,
        'ingest_sha256' => hash('sha256', $integrityPayload),
        'ingest_size' => strlen($integrityPayload),
        'integrity_captured_at' => '2026-07-30 12:00:00.000000',
    ], $integrityFixture);
    $assert(
        $verifiedIntegrity['state'] === 'verified'
            && $verifiedIntegrity['sha256']
                === hash('sha256', $integrityPayload),
        'Valid SHA-256/size ingest evidence was rejected'
    );

    file_put_contents(
        $integrityFixture,
        str_repeat('X', strlen($integrityPayload)),
        LOCK_EX
    );
    $tamperRejected = false;
    try {
        estab_attachment_integrity_verify_file([
            'integrity_required' => 1,
            'ingest_sha256' => hash('sha256', $integrityPayload),
            'ingest_size' => strlen($integrityPayload),
            'integrity_captured_at' => '2026-07-30 12:00:00.000000',
        ], $integrityFixture);
    } catch (EstabAttachmentIntegrityException) {
        $tamperRejected = true;
    }
    $assert(
        $tamperRejected,
        'Same-size attachment tampering was not rejected'
    );

    $legacyIntegrity = estab_attachment_integrity_verify_file([
        'integrity_required' => 0,
        'ingest_sha256' => null,
        'ingest_size' => null,
        'integrity_captured_at' => null,
    ], $integrityFixture);
    $assert(
        $legacyIntegrity['state'] === 'legacy_unverifiable'
            && $legacyIntegrity['sha256'] === null
            && $legacyIntegrity['statement']
                === 'Integrität beim Eingang nicht belegbar',
        'Legacy attachment was given invented ingest evidence'
    );
} finally {
    @unlink($integrityFixture);
}

$liveMessage = [
    'einsatz_id' => 12,
    '00_lfd' => 7,
    '04_nummer' => 1,
    '04_richtung' => 'E',
    '11_rufnummer' => '0711 123456',
    '12_betreff' => 'Lagemeldung',
    '12_inhalt' => 'Terminal gebundener Inhalt',
    'x00_status' => 8,
    'x01_abschluss' => 't',
];
$terminalSnapshot = estab_message_terminal_snapshot($liveMessage);
$fieldSnapshot = estab_message_evidence_snapshot([
    'terminal_snapshot_version' =>
        ESTAB_MESSAGE_TERMINAL_SNAPSHOT_CURRENT,
    'terminal_message' => $terminalSnapshot,
    'terminal_snapshot_sha256' =>
        estab_message_terminal_snapshot_sha256($liveMessage),
]);
$messageEvent = [
    'event_id' => 19,
    'einsatz_id' => 12,
    'message_id' => 7,
    'event_type' => 'incoming_routed',
    'occurred_at' => '2026-07-29 10:00:00.000000',
    'recorded_at' => '2026-07-29 10:00:01.000000',
    'actor_user' => 'Sichter',
    'actor_code' => 'si001',
    'actor_function' => 'Si',
    'from_status' => 4,
    'to_status' => 8,
    'field_snapshot' => $fieldSnapshot,
    'snapshot_sha256' => hash('sha256', $fieldSnapshot),
    'previous_event_sha256' => null,
];
$messageEvent['event_sha256'] =
    estab_incident_export_message_event_hash(12, $messageEvent);
$messageHead = [
    'message_id' => 7,
    'einsatz_id' => 12,
    'event_count' => 1,
    'last_event_sha256' => $messageEvent['event_sha256'],
    'updated_at' => '2026-07-29 10:00:01.000000',
];
$messageStatus = estab_incident_export_message_evidence_status(
    12,
    [$liveMessage],
    [$messageEvent],
    [$messageHead]
);
$assert(
    $messageStatus['valid'] === true
        && $messageStatus['terminal_binding_complete'] === true
        && $messageStatus['terminal_count'] === 1
        && $messageStatus['terminal_mismatches'] === 0,
    'Valid terminal message evidence was rejected'
);
$v1LiveMessage = array_replace($liveMessage, [
    '11_rufnummer' => '',
    '12_betreff' => '',
]);
$v1TerminalSnapshot = estab_message_terminal_snapshot(
    $v1LiveMessage,
    ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
);
$v1FieldSnapshot = estab_message_evidence_snapshot([
    'terminal_message' => $v1TerminalSnapshot,
    'terminal_snapshot_sha256' =>
        estab_message_terminal_snapshot_sha256(
            $v1LiveMessage,
            ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
        ),
]);
$v1MessageEvent = array_replace($messageEvent, [
    'field_snapshot' => $v1FieldSnapshot,
    'snapshot_sha256' => hash('sha256', $v1FieldSnapshot),
]);
$v1MessageEvent['event_sha256'] =
    estab_incident_export_message_event_hash(12, $v1MessageEvent);
$v1MessageHead = array_replace($messageHead, [
    'last_event_sha256' => $v1MessageEvent['event_sha256'],
]);
$v1Status = estab_incident_export_message_evidence_status(
    12,
    [$v1LiveMessage],
    [$v1MessageEvent],
    [$v1MessageHead]
);
$assert(
    !array_key_exists('11_rufnummer', $v1TerminalSnapshot)
        && !array_key_exists('12_betreff', $v1TerminalSnapshot)
        && $v1Status['valid'] === true
        && $v1Status['terminal_binding_complete'] === true
        && $v1Status['terminal_mismatches'] === 0,
    'Implicit V1 terminal evidence was not exported as valid'
);
$tamperedMessage = $liveMessage;
$tamperedMessage['12_inhalt'] = 'Nachträglich manipuliert';
$tamperedStatus = estab_incident_export_message_evidence_status(
    12,
    [$tamperedMessage],
    [$messageEvent],
    [$messageHead]
);
$assert(
    $tamperedStatus['valid'] === false
        && $tamperedStatus['terminal_mismatches'] === 1,
    'Terminal event was not compared with the live status-8 message'
);
foreach ([
    '11_rufnummer' => '0711 999999',
    '12_betreff' => 'Manipulierter Betreff',
] as $field => $value) {
    $tamperedV2Message = array_replace($liveMessage, [$field => $value]);
    $tamperedV2Status = estab_incident_export_message_evidence_status(
        12,
        [$tamperedV2Message],
        [$messageEvent],
        [$messageHead]
    );
    $assert(
        $tamperedV2Status['valid'] === false
            && $tamperedV2Status['terminal_mismatches'] === 1,
        'V2 export evidence did not bind ' . $field
    );
}
$legacySnapshot = '{"legacy_import":true}';
$legacyEvent = array_replace($messageEvent, [
    'event_type' => 'legacy_import',
    'field_snapshot' => $legacySnapshot,
    'snapshot_sha256' => hash('sha256', $legacySnapshot),
]);
$legacyEvent['event_sha256'] =
    estab_incident_export_message_event_hash(12, $legacyEvent);
$legacyHead = array_replace($messageHead, [
    'last_event_sha256' => $legacyEvent['event_sha256'],
]);
$legacyStatus = estab_incident_export_message_evidence_status(
    12,
    [$liveMessage],
    [$legacyEvent],
    [$legacyHead]
);
$assert(
    $legacyStatus['valid'] === true
        && $legacyStatus['terminal_binding_complete'] === false
        && $legacyStatus['terminal_unverifiable'] === 1,
    'Legacy terminal evidence was not reported as historically unverifiable'
);

$operationsEvent = [
    'betriebsereignis_id' => 1,
    'einsatz_id' => 12,
    'sequenz' => 1,
    'objekttyp' => 'DIENSTSCHICHT',
    'objekt_id' => 3,
    'aktion' => 'dienstschicht_angelegt',
    'akteur_kuerzel' => 'admin',
    'akteur_funktion' => 'S2',
    'ereigniszeit' => '2026-07-29 10:01:00.000000',
    'details_json' => '{"status":"GEPLANT"}',
    'vorheriger_hash' => str_repeat('0', 64),
];
$operationsEvent['ereignis_hash'] = hash('sha256', implode('|', [
    '12',
    '1',
    'DIENSTSCHICHT',
    '3',
    'dienstschicht_angelegt',
    'admin',
    'S2',
    '2026-07-29 10:01:00.000000',
    '{"status":"GEPLANT"}',
    str_repeat('0', 64),
]));
$operationsStatus = estab_incident_export_operations_evidence_status(
    12,
    [$operationsEvent],
    [[
        'einsatz_id' => 12,
        'letzte_sequenz' => 1,
        'letzter_hash' => $operationsEvent['ereignis_hash'],
    ]]
);
$assert(
    $operationsStatus['valid'] === true
        && $operationsStatus['stored_head_sha256']
            === $operationsEvent['ereignis_hash'],
    'Valid operations evidence was rejected'
);
$brokenOperations = $operationsEvent;
$brokenOperations['details_json'] = '{"status":"AKTIV"}';
$assert(
    estab_incident_export_operations_evidence_status(
        12,
        [$brokenOperations],
        [[
            'einsatz_id' => 12,
            'letzte_sequenz' => 1,
            'letzter_hash' => $operationsEvent['ereignis_hash'],
        ]]
    )['valid'] === false,
    'Tampered operations evidence was accepted'
);

$source = file_get_contents(__DIR__ . '/../../app/incident_export.php');
if (!is_string($source)) {
    throw new RuntimeException('Could not read incident export source');
}
$assert(
    str_contains(
        $source,
        "BINARY ttb_row.`estab_entry_type` = BINARY 'nachricht'"
    )
        && str_contains(
            $source,
            "' ORDER BY ttb_row.`estab_book_lfd`,'"
        )
        && str_contains(
            $source,
            "' ttb_row.`tbb_lfd-nr` LIMIT 1)'"
        )
        && !str_contains(
            $source,
            "ttb_row.`estab_entry_type` = 'nachricht'"
        ),
    'Incident export accepts a case-variant or non-deterministic TBB proof'
);
foreach ([
    'FROM `nv_etb` AS entry_row',
    'FROM `nv_tbb` AS entry_row',
    'LEFT JOIN `nv_etb` AS correction_row',
    'LEFT JOIN `nv_tbb` AS correction_row',
    'correction_row.`estab_book_lfd`',
    'AS `estab_correction_book_lfd`',
    'correction_row.`einsatz_id` = entry_row.`einsatz_id`',
    'WHERE entry_row.`einsatz_id` = ?',
    'FROM `nv_nachrichten` WHERE `einsatz_id` = ?',
    'FROM `nv_nachrichten_ereignisse`',
    'FROM `nv_nachrichten_nachweiskopf`',
    'FROM `nv_zugangsschichten` WHERE `einsatz_id` = ?',
    'FROM `nv_zugangsschicht_mitglieder` AS membership',
    "'access_shifts' => \$accessShifts",
    "'access_shift_memberships' => \$accessShiftMemberships",
    'FROM `nv_dienstschichten` WHERE `einsatz_id` = ?',
    'FROM `nv_dienstbesetzungen` AS b',
    'WHERE s.`einsatz_id` = ?',
    'FROM `nv_dienstuebergaben`',
    'FROM `nv_fernmeldeplaene`',
    'FROM `nv_fernmeldeplan_eintraege` AS e',
    'WHERE p.`einsatz_id` = ?',
    'FROM `nv_melderauftraege` WHERE `einsatz_id` = ?',
    'FROM `nv_betriebsereignisse` WHERE `einsatz_id` = ?',
    'FROM `nv_betriebsereignis_kopf` WHERE `einsatz_id` = ?',
    'WHERE `einsatz_id` = ? AND `status` = 1',
    '`integrity_required`,',
    '`ingest_sha256`, `ingest_size`,',
    'estab_attachment_integrity_verify_file(',
    "'attachments_verified'",
    "'attachments_legacy'",
    'estab_incident_export_recipient_matrix($connection)',
    '`06_befwegausw`, `07_durchspruch`',
    '`08_befhinwausw`, `09_vorrangstufe`',
    '`10_anschrift`, `11_rufnummer`',
    '`11_gesprnotiz`, `12_anhang`, `12_betreff`',
    '`ruecknachricht_vorhanden`, `ruecknachricht`',
    '`x04_druck`, `x05_druck_d`, `99_lstacc`',
    '`estab_event_time`,',
    '`estab_correction_of`,',
    'entry_row.`estab_shift_id`,',
    'entry_row.`estab_assignment`,',
    "' AND entry_row.`estab_shift_id` = ?'",
    'estab_incident_export_resolve_logbook_scope(',
    'WHERE `einsatz_id` = ? AND `dienstschicht_id` = ?',
    "'logbook_scope' => \$resolvedLogbookScope",
    'estab_message_terminal_snapshot_stored_version(',
    'estab_message_terminal_snapshot_matches_live(',
    'estab_incident_export_operations_evidence_status(',
    'estab_incident_export_etb_attachment_number_map(',
    "'etb_attachment_numbers'",
    'estab_file_resolve(',
    'Ein Nachrichtenvordruck verweist auf einen nicht ',
    '$pdf->addAttachmentPages($embeddedIndex)',
    "'attachment_rendered_pages'",
    'hash(\'sha256\', $bytes)',
] as $requiredBoundary) {
    $assert(
        str_contains($source, $requiredBoundary),
        'Incident export boundary is missing: ' . $requiredBoundary
    );
}
$assert(
    !str_contains($source, 'SELECT *'),
    'Incident export uses an unbounded SELECT *'
);

$controller = file_get_contents(
    __DIR__ . '/../../4fadm/incident_export.php'
);
$dashboard = file_get_contents(__DIR__ . '/../../4fadm/admin.php');
$dockerfile = file_get_contents(__DIR__ . '/../../Dockerfile');
$runtimeVerifier = file_get_contents(
    __DIR__ . '/../../docker/app/verify-runtime-surface.sh'
);
$pdfRenderer = file_get_contents(__DIR__ . '/../../app/incident_pdf.php');
$messageTemplate = file_get_contents(
    __DIR__ . '/../../4fbak/backup_pdf.php'
);
foreach (
    [
        $controller,
        $dashboard,
        $dockerfile,
        $runtimeVerifier,
        $pdfRenderer,
        $messageTemplate,
    ] as $surface
) {
    $assert(is_string($surface), 'Incident PDF integration source is unreadable');
}
$assert(
    str_contains($pdfRenderer, 'extends vordruckaspdf')
        && str_contains($pdfRenderer, '$this->set_message_form_data($message)')
        && str_contains($pdfRenderer, 'parent::Header()')
        && str_contains($pdfRenderer, 'parent::Footer()')
        && !str_contains($pdfRenderer, "'Datensatz' => \$recordId"),
    'Incident dossier does not reuse the generated message-form renderer'
);
$assert(
    str_contains($pdfRenderer, 'Optionale Zugangsschichten')
        && str_contains($pdfRenderer, 'Historischer Dienstbetrieb (Legacy-Nachweis)')
        && str_contains($pdfRenderer, 'Access-shift memberships must be arrays.'),
    'Incident dossier omits current access shifts or fails to distinguish legacy duty records'
);
$assert(
    str_contains($pdfRenderer, "'estab_correction_book_lfd'")
        && str_contains(
            $pdfRenderer,
            'Korrekturverweis vorhanden; lokale ETB-Nr. nicht auflösbar.'
        )
        && str_contains(
            $pdfRenderer,
            'Korrekturverweis vorhanden; lokale TBB-Nr. nicht auflösbar.'
        )
        && !str_contains(
            $pdfRenderer,
            "['estab_book_lfd', 'estab_buch_lfd', 'etb_lfd-nr', 'lfd']"
        )
        && !str_contains(
            $pdfRenderer,
            "['estab_book_lfd', 'estab_buch_lfd', 'tbb_lfd-nr', 'lfd']"
        ),
    'PDF renderer can still label global logbook IDs as local numbers'
);
$assert(
    !str_contains($messageTemplate, 'Nur für den Dienstgebrauch')
        && !str_contains($messageTemplate, '4fbak/logo.png')
        && !str_contains($messageTemplate, '/logo.png')
        && !str_contains($messageTemplate, "ini_set('memory_limit'"),
    'Message-form template still prints removed assets or lowers dossier memory'
);
$assert(
    str_contains($messageTemplate, '$data ["11_rufnummer"]')
        && str_contains($messageTemplate, '$data ["12_betreff"]')
        && str_contains(
            $messageTemplate,
            '$this->db_dataset ["11_rufnummer"]'
        )
        && str_contains(
            $messageTemplate,
            '$this->db_dataset ["12_betreff"]'
        )
        && str_contains($messageTemplate, '"Ruf Nr."')
        && str_contains($messageTemplate, 'function draw_fitted_textfield'),
    'Message-form PDF omits the official phone or subject fields'
);
$assert(
    str_contains($controller, "empty(\$_SERVER['REMOTE_USER'])")
        && str_contains(
            $controller,
            'estab_csrf_require_post($_SERVER, $_POST)'
        )
        && str_contains($controller, 'estab_incident_positive_id(')
        && str_contains($controller, 'MYSQLI_TRANS_START_READ_ONLY')
        && str_contains(
            $controller,
            'MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT'
        ),
    'Incident PDF controller lacks admin, CSRF, strict-ID, or snapshot boundary'
);
$assert(
    str_contains($controller, "'pdf_export'")
        && str_contains($controller, "\$_POST['logbook_scope'] ?? 'all'")
        && str_contains(
            $controller,
            "'logbook_scope' => \$bundle['logbook_scope']"
        )
        && str_contains($controller, 'name="logbook_scope"')
        && str_contains($controller, "'sha256' => \$rendered['sha256']")
        && str_contains(
            $controller,
            "'attachment_visible_pages' =>"
        )
        && str_contains(
            $controller,
            "\$rendered['attachment_rendered_pages']"
        )
        && str_contains($controller, "header('Content-Type: application/pdf')")
        && str_contains($controller, "Content-Security-Policy: sandbox"),
    'Incident PDF response or audit boundary is incomplete'
);
$assert(
    str_contains(
        $controller,
        'ETB-Einträge gemäß der oben gewählten ETB/TBB-Ausgabe'
    )
        && str_contains(
            $controller,
            'TBB-Einträge gemäß der oben gewählten ETB/TBB-Ausgabe'
        )
        && !str_contains($controller, 'Alle ETB-Einträge dieses Einsatzes')
        && !str_contains($controller, 'Alle TBB-Einträge dieses Einsatzes'),
    'Incident export descriptions contradict the selected logbook scope'
);
$assert(
    str_contains($dashboard, "'key' => 'incident-pdf'")
        && str_contains($dashboard, "'href' => 'incident_export.php'")
        && str_contains($dashboard, "'key' => 'incidents'"),
    'Administration dashboard omits incident PDF or incident management'
);
$assert(
    str_contains($dockerfile, '4fadm/incident_export.php')
        && str_contains($dockerfile, '4fadm/incidents.php')
        && str_contains($dockerfile, 'poppler-utils')
        && str_contains($dockerfile, 'command -v pdfinfo >/dev/null')
        && str_contains($dockerfile, 'command -v pdftoppm >/dev/null')
        && !str_contains($dockerfile, '4fbak/logo.png')
        && str_contains($runtimeVerifier, '4fadm/incident_export.php')
        && str_contains($runtimeVerifier, '4fadm/incidents.php')
        && !str_contains($runtimeVerifier, '4fbak/logo.png')
        && str_contains($runtimeVerifier, 'app/incident_export.php')
        && str_contains($runtimeVerifier, 'app/incident_pdf.php'),
    'Container runtime omits incident PDF or incident management files'
);

echo 'incident export security: OK (' . $assertions . " assertions)\n";
