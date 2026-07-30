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
    '12_inhalt' => 'Terminal gebundener Inhalt',
    'x00_status' => 8,
    'x01_abschluss' => 't',
];
$terminalSnapshot = estab_message_terminal_snapshot($liveMessage);
$fieldSnapshot = estab_message_evidence_snapshot([
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
foreach ([
    'FROM `nv_etb` WHERE `einsatz_id` = ?',
    'FROM `nv_tbb` WHERE `einsatz_id` = ?',
    'FROM `nv_nachrichten` WHERE `einsatz_id` = ?',
    'FROM `nv_nachrichten_ereignisse`',
    'FROM `nv_nachrichten_nachweiskopf`',
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
    '`10_anschrift`, `11_gesprnotiz`',
    '`ruecknachricht_vorhanden`, `ruecknachricht`',
    '`x04_druck`, `x05_druck_d`, `99_lstacc`',
    '`estab_event_time`,',
    '`estab_correction_of`,',
    'estab_message_terminal_snapshot_sha256(',
    'estab_incident_export_operations_evidence_status(',
    'estab_file_resolve(',
    'Ein Nachrichtenvordruck verweist auf einen nicht ',
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
    !str_contains($messageTemplate, 'Nur für den Dienstgebrauch')
        && !str_contains($messageTemplate, '4fbak/logo.png')
        && !str_contains($messageTemplate, '/logo.png')
        && !str_contains($messageTemplate, "ini_set('memory_limit'"),
    'Message-form template still prints removed assets or lowers dossier memory'
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
        && str_contains($controller, "'sha256' => \$rendered['sha256']")
        && str_contains($controller, "header('Content-Type: application/pdf')")
        && str_contains($controller, "Content-Security-Policy: sandbox"),
    'Incident PDF response or audit boundary is incomplete'
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
        && !str_contains($dockerfile, '4fbak/logo.png')
        && str_contains($runtimeVerifier, '4fadm/incident_export.php')
        && str_contains($runtimeVerifier, '4fadm/incidents.php')
        && !str_contains($runtimeVerifier, '4fbak/logo.png')
        && str_contains($runtimeVerifier, 'app/incident_export.php')
        && str_contains($runtimeVerifier, 'app/incident_pdf.php'),
    'Container runtime omits incident PDF or incident management files'
);

echo 'incident export security: OK (' . $assertions . " assertions)\n";
