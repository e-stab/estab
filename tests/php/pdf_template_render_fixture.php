<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/bootstrap.php';
require_once __DIR__ . '/pdf_test_fixture.php';
require_once $root . '/4fbak/backup_pdf.php';
require_once $root . '/app/incident_pdf.php';

$outputDirectory = $argv[1] ?? '';
if (
    !is_string($outputDirectory)
    || !str_starts_with($outputDirectory, '/')
    || !is_dir($outputDirectory)
    || !is_writable($outputDirectory)
) {
    throw new RuntimeException(
        'Usage: php pdf_template_render_fixture.php /absolute/output-directory'
    );
}

$_SERVER['DOCUMENT_ROOT'] = $root;
$message = estab_pdf_test_message_fixture();
$matrix = estab_pdf_test_recipient_matrix();

$single = new vordruckaspdf($message, $matrix);
$single->SetCompression(false);
$single->SetTitle('eStab message-form render fixture');
$singleBytes = $single->render_message_form_document();

$incident = [
    'einsatz_id' => 1,
    'kennung' => 'RENDER-001',
    'name' => 'PDF-Vorlagenvergleich',
    'beginn' => '2026-07-29 07:45:00',
    'ende' => null,
    'ort' => 'Musterstadt',
    'organisation' => 'Kreis Musterstadt',
    'einsatzleitung' => 'Leitung Rendernachweis',
    'beschreibung' => 'Repräsentatives DV-1-101-Einsatzdossier',
    'estab_status' => 'open',
    'estab_closed_at' => null,
    'estab_closed_by' => null,
    'estab_close_note' => null,
    'estab_retain_until' => null,
    'estab_legal_hold' => 0,
    'estab_legal_hold_reason' => null,
    'estab_legal_hold_at' => null,
    'estab_legal_hold_by' => null,
];
$attachmentPath = $outputDirectory . '/original-attachment.txt';
$attachmentPayload = "eStab PDF embedded-file render proof\n";
if (
    file_put_contents($attachmentPath, $attachmentPayload)
        !== strlen($attachmentPayload)
) {
    throw new RuntimeException('Could not write render-proof attachment');
}

$dossier = new EstabIncidentPdf($incident, 1024 * 1024, $matrix);
$dossier->SetCompression(false);
$dossier->embedAttachment(
    $attachmentPath,
    'Render-Anlage.txt',
    'text/plain',
    'Render-Nachweis des eingebetteten Anhangs',
    hash('sha256', $attachmentPayload),
    strlen($attachmentPayload)
);
$dossier->addMessages([$message], [1 => ['Render-Anlage.txt']]);
$dossierBytes = $dossier->Output('', 'S');

$longMessage = array_replace($message, [
    '12_anhang' => '',
    '12_inhalt' => str_repeat(
        "Mehrseitiger Formularinhalt\n",
        45
    ) . 'ENDE-MEHRSEITIGER-VORDRUCK',
]);
$longSingle = new vordruckaspdf($longMessage, $matrix);
$longSingle->SetCompression(false);
$longSingleBytes = $longSingle->render_message_form_document();

$longDossier = new EstabIncidentPdf(
    $incident,
    1024 * 1024,
    $matrix
);
$longDossier->SetCompression(false);
$longDossier->addMessages([$longMessage], [1 => []]);
$longDossierBytes = $longDossier->Output('', 'S');

$completeDossier = new EstabIncidentPdf(
    $incident,
    1024 * 1024,
    $matrix
);
$completeDossier->SetCompression(false);
$completeAttachment = $completeDossier->embedAttachment(
    $attachmentPath,
    'Render-Anlage.txt',
    'text/plain',
    'Render-Nachweis des eingebetteten Anhangs',
    hash('sha256', $attachmentPayload),
    strlen($attachmentPayload)
);
$sections = [
    'etb',
    'ttb',
    'messages',
    'attachments',
    'message_evidence',
    'duty',
    's6_plans',
    'courier',
    'operations_evidence',
];
$completeDossier->addCover(
    $incident,
    $sections,
    [
        'etb' => 1,
        'ttb' => 1,
        'messages' => 1,
        'attachments' => 1,
        'attachments_verified' => 1,
        'attachments_legacy' => 0,
        'message_evidence' => 1,
        'duty' => 3,
        's6_plans' => 1,
        'courier' => 1,
        'operations_evidence' => 1,
    ],
    '29.07.2026 08:30:00 CEST',
    'PDF-Rendernachweis',
    [
        'message' => [
            'valid' => true,
            'event_count' => 1,
            'head_count' => 1,
            'head_mismatches' => 0,
            'head_set_sha256' => str_repeat('a', 64),
            'terminal_binding_complete' => true,
            'terminal_unverifiable' => 0,
        ],
        'operations' => [
            'valid' => true,
            'event_count' => 1,
            'stored_sequence' => 1,
            'stored_head_sha256' => str_repeat('b', 64),
            'calculated_head_sha256' => str_repeat('b', 64),
        ],
    ]
);
$completeDossier->addLogbook('ETB', [[
    'etb_lfd-nr' => 1,
    'etb_time' => '2026-07-29 08:00:00',
    'estab_event_time' => '2026-07-29 07:59:30.000000',
    'estab_recorded_at' => '2026-07-29 08:00:00.000000',
    'estab_event_type' => 'message_reference',
    'estab_message_id' => 1,
    'estab_attachment_id' => null,
    'estab_reference' => 'Lagekarte Abschnitt Nord',
    'estab_correction_of' => null,
    'etb_aktion' => 'Produktionsnaher Layoutwechsel ETB',
    'etb_bemerk' => 'Vor der Nachrichtenvorlage',
    'etb_benutzer' => 'Rendernachweis',
    'etb_kuerzel' => 'REN001',
    'etb_funktion' => 'S2',
]]);
$completeDossier->addLogbook('TBB', [[
    'tbb_lfd-nr' => 1,
    'tbb_time' => '2026-07-29 08:05:00',
    'tbb_aktion' => 'Produktionsnaher Layoutwechsel TBB',
    'tbb_bemerk' => 'Unmittelbar vor der Nachrichtenvorlage',
    'tbb_benutzer' => 'Rendernachweis',
    'tbb_kuerzel' => 'REN002',
    'tbb_funktion' => 'A/W',
]]);
$completeDossier->addMessages(
    [$message],
    [1 => ['Render-Anlage.txt']]
);
$completeDossier->addMessageEvidence(
    [[
        'event_id' => 10,
        'einsatz_id' => 1,
        'message_id' => 1,
        'event_type' => 'incoming_routed',
        'occurred_at' => '2026-07-29 08:12:00.000000',
        'recorded_at' => '2026-07-29 08:12:01.000000',
        'actor_user' => 'Sichter Rendernachweis',
        'actor_code' => 'REN003',
        'actor_function' => 'Si',
        'from_status' => 4,
        'to_status' => 8,
        'field_snapshot' =>
            '{"direction":"E","terminal_snapshot_sha256":"fixture"}',
        'snapshot_sha256' => str_repeat('c', 64),
        'previous_event_sha256' => null,
        'event_sha256' => str_repeat('d', 64),
    ]],
    [[
        'message_id' => 1,
        'einsatz_id' => 1,
        'event_count' => 1,
        'last_event_sha256' => str_repeat('d', 64),
        'updated_at' => '2026-07-29 08:12:01.000000',
    ]],
    [
        'valid' => true,
        'event_count' => 1,
        'message_count' => 1,
        'head_count' => 1,
        'head_mismatches' => 0,
        'broken_event_id' => null,
        'head_set_sha256' => str_repeat('a', 64),
        'terminal_count' => 1,
        'terminal_mismatches' => 0,
        'terminal_unverifiable' => 0,
        'terminal_binding_complete' => true,
    ]
);
$completeDossier->addDutyRecords(
    [[
        'dienstschicht_id' => 21,
        'einsatz_id' => 1,
        'nummer' => 1,
        'bezeichnung' => 'Tagschicht',
        'status' => 'UEBERGEBEN',
        'vorgaenger_id' => null,
        'erstellt_am' => '2026-07-29 07:45:00.000000',
        'erstellt_von' => 'PDF-Rendernachweis',
        'aktiviert_am' => '2026-07-29 08:00:00.000000',
        'beendet_am' => '2026-07-29 16:00:00.000000',
    ]],
    [[
        'dienstbesetzung_id' => 31,
        'dienstschicht_id' => 21,
        'dienstschicht_nummer' => 1,
        'benutzer_kuerzel' => 'REN003',
        'funktion' => 'Si',
        'rolle' => 'Stab',
        'status' => 'ABGELOEST',
        'zugewiesen_am' => '2026-07-29 07:46:00.000000',
        'zugewiesen_von' => 'PDF-Rendernachweis',
        'angenommen_am' => '2026-07-29 07:47:00.000000',
        'abgeloest_am' => '2026-07-29 16:00:00.000000',
        'nachfolger_id' => 32,
    ]],
    [[
        'dienstuebergabe_id' => 41,
        'einsatz_id' => 1,
        'von_dienstschicht_id' => 21,
        'an_dienstschicht_id' => 22,
        'zusammenfassung' =>
            'Lage, Nachrichten und offene Aufträge vollständig übergeben.',
        'uebergeben_am' => '2026-07-29 16:00:00.000000',
        'uebergeben_von' => 'PDF-Rendernachweis',
        'angenommen_von' => 'REN004',
    ]],
    [[
        'dienstuebergabe_anfrage_id' => 42,
        'einsatz_id' => 1,
        'von_dienstschicht_id' => 21,
        'an_dienstschicht_id' => 22,
        'zusammenfassung' => 'Nachfolgebestätigung steht noch aus.',
        'status' => 'INITIIERT',
        'initiiert_am' => '2026-07-29 15:50:00.000000',
        'initiiert_von' => 'PDF-Rendernachweis',
        'bestaetigt_am' => null,
        'bestaetigt_von' => null,
        'bestaetigt_mit_besetzung_id' => null,
        'dienstuebergabe_id' => null,
        'storniert_am' => null,
        'storniert_von' => null,
        'stornierungsgrund' => null,
    ], [
        'dienstuebergabe_anfrage_id' => 43,
        'einsatz_id' => 1,
        'von_dienstschicht_id' => 21,
        'an_dienstschicht_id' => 22,
        'zusammenfassung' => 'Falscher Nachfolger ausgewählt.',
        'status' => 'STORNIERT',
        'initiiert_am' => '2026-07-29 15:52:00.000000',
        'initiiert_von' => 'PDF-Rendernachweis',
        'bestaetigt_am' => null,
        'bestaetigt_von' => null,
        'bestaetigt_mit_besetzung_id' => null,
        'dienstuebergabe_id' => null,
        'storniert_am' => '2026-07-29 15:53:00.000000',
        'storniert_von' => 'PDF-Rendernachweis',
        'stornierungsgrund' => 'Falsche Nachfolgeschicht',
    ], [
        'dienstuebergabe_anfrage_id' => 44,
        'einsatz_id' => 1,
        'von_dienstschicht_id' => 21,
        'an_dienstschicht_id' => 22,
        'zusammenfassung' => 'Persönliche Nachfolgebestätigung.',
        'status' => 'BESTAETIGT',
        'initiiert_am' => '2026-07-29 15:55:00.000000',
        'initiiert_von' => 'PDF-Rendernachweis',
        'bestaetigt_am' => '2026-07-29 16:00:00.000000',
        'bestaetigt_von' => 'REN004',
        'bestaetigt_mit_besetzung_id' => 32,
        'dienstuebergabe_id' => 41,
        'storniert_am' => null,
        'storniert_von' => null,
        'stornierungsgrund' => null,
    ]]
);
$completeDossier->addS6Plans(
    [[
        'fernmeldeplan_id' => 51,
        'einsatz_id' => 1,
        'version' => 2,
        'status' => 'AKTIV',
        'einsatzbezeichnung' => 'Render-Einsatz Musterstadt',
        'herkunft' => 'S6 Führungsstelle',
        'gueltig_ab' => '2026-07-29 08:00:00',
        'gueltig_bis' => '2026-07-30 08:00:00',
        'betriebsleitung' => 'LdF Rendernachweis',
        'bemerkungen' => 'Freigegebene produktionsnahe Planversion',
        'erstellt_am' => '2026-07-29 07:50:00.000000',
        'erstellt_von' => 'REN005',
        'freigegeben_am' => '2026-07-29 07:55:00.000000',
        'freigegeben_von' => 'REN005',
    ]],
    [[
        'fernmeldeplan_eintrag_id' => 61,
        'fernmeldeplan_id' => 51,
        'plan_version' => 2,
        'sortierung' => 1,
        'betriebsstelle' => 'Leitstelle Musterstadt',
        'rufname' => 'Florian Musterstadt',
        'medium' => 'Fu',
        'kanal' => 'Kanal 4',
        'bandlage' => '2 m',
        'verkehrsform' => 'Sternverkehr',
        'besondere_vermerke' => 'Vorrang für Einsatzleitung',
        'bemerkungen' => 'Stündliche Funkprobe',
    ]]
);
$completeDossier->addCourierOrders([[
    'melderauftrag_id' => 71,
    'einsatz_id' => 1,
    'nachricht_id' => 1,
    'melder_kuerzel' => 'REN006',
    'ziel' => 'Leitstelle Musterstadt',
    'status' => 'GEMELDET',
    'beauftragt_am' => '2026-07-29 08:15:00.000000',
    'beauftragt_von' => 'REN007',
    'uebernommen_am' => '2026-07-29 08:16:00.000000',
    'tatsaechlicher_empfaenger' => 'Frau Zielperson',
    'uebergeben_am' => '2026-07-29 08:25:00.000000',
    'ruecknachricht_vorhanden' => true,
    'ruecknachricht' => 'Empfang vollständig bestätigt',
    'rueckweg_am' => '2026-07-29 08:26:00.000000',
    'zurueck_am' => '2026-07-29 08:35:00.000000',
    'abschlussvermerk' => 'Rückkehr und Empfang an FmZt gemeldet',
    'gemeldet_am' => '2026-07-29 08:36:00.000000',
    'gemeldet_an' => 'REN008',
    'abgebrochen_am' => null,
    'abbruchgrund' => null,
]]);
$completeDossier->addOperationsEvidence(
    [[
        'betriebsereignis_id' => 81,
        'einsatz_id' => 1,
        'sequenz' => 1,
        'objekttyp' => 'MELDERAUFTRAG',
        'objekt_id' => 71,
        'aktion' => 'melderauftrag_gemeldet',
        'akteur_kuerzel' => 'REN008',
        'akteur_funktion' => 'A/W',
        'ereigniszeit' => '2026-07-29 08:36:00.000000',
        'details_json' =>
            '{"status":"GEMELDET","ziel":"Leitstelle Musterstadt"}',
        'vorheriger_hash' => str_repeat('0', 64),
        'ereignis_hash' => str_repeat('b', 64),
    ]],
    [[
        'einsatz_id' => 1,
        'letzte_sequenz' => 1,
        'letzter_hash' => str_repeat('b', 64),
    ]],
    [
        'valid' => true,
        'event_count' => 1,
        'failed_sequence' => null,
        'calculated_head_sha256' => str_repeat('b', 64),
        'stored_sequence' => 1,
        'stored_head_sha256' => str_repeat('b', 64),
    ]
);
$completeDossier->addAttachmentIndex([[
    'display_name' => 'Render-Anlage.txt',
    'stored_name' => $completeAttachment['name'],
    'size' => $completeAttachment['size'],
    'sha256' => $completeAttachment['sha256'],
    'mime' => $completeAttachment['mime'],
    'integrity_state' => 'verified',
    'integrity_statement' =>
        'SHA-256 und Größe entsprechen dem Eingangsnachweis',
    'message_ids' => [1],
]]);
$completeDossierBytes = $completeDossier->Output('', 'S');

foreach ([
    'message-form.pdf' => $singleBytes,
    'dossier-message-form.pdf' => $dossierBytes,
    'long-message-form.pdf' => $longSingleBytes,
    'dossier-long-message-form.pdf' => $longDossierBytes,
    'dossier-all.pdf' => $completeDossierBytes,
] as $filename => $bytes) {
    if (
        !str_starts_with($bytes, '%PDF-')
        || !str_ends_with($bytes, "%%EOF\n")
        || file_put_contents($outputDirectory . '/' . $filename, $bytes)
            !== strlen($bytes)
    ) {
        throw new RuntimeException('Could not write complete PDF render fixture');
    }
}

echo "PDF template render fixtures: OK\n";
