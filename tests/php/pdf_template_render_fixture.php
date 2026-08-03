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

$priorityDocuments = [];
foreach ([
    'none' => '',
    'sofort' => 'sss',
    'blitz' => 'bbb',
    'staatsnot' => 'aaa',
] as $priorityName => $priorityValue) {
    $priorityPdf = new vordruckaspdf(
        array_replace($message, ['09_vorrangstufe' => $priorityValue]),
        $matrix
    );
    $priorityPdf->SetCompression(false);
    $priorityDocuments[
        'message-form-priority-' . $priorityName . '.pdf'
    ] = $priorityPdf->render_message_form_document();
}

$mediumDocuments = [];
foreach ([
    'none' => '',
    'fu' => 'Fu',
    'fe' => 'Fe',
    'me' => 'Me',
    'fax' => 'FAX',
    'fs' => 'FS',
    'at' => '@',
] as $mediumName => $mediumValue) {
    $mediumPdf = new vordruckaspdf(
        array_replace($message, [
            '01_medium' => $mediumValue,
            '06_befwegausw' => $mediumValue,
        ]),
        $matrix
    );
    $mediumPdf->SetCompression(false);
    $mediumDocuments[
        'message-form-medium-' . $mediumName . '.pdf'
    ] = $mediumPdf->render_message_form_document();
}

$incident = [
    'einsatz_id' => 1,
    'kennung' => 'RENDER-001',
    'name' => 'PDF-Vorlagenvergleich',
    'beginn' => '2026-07-29 07:45:00',
    'ende' => null,
    'ort' => 'Musterstadt',
    'organisation' => 'Kreis Musterstadt',
    'fuehrungsstellenname' => 'Führungsstelle Musterstadt',
    'einsatzleitung' => 'Leitung Rendernachweis',
    'beschreibung' => 'Repräsentatives DV-1-101-Einsatzdossier',
    'estab_permission_mode' => 'STRICT',
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
$imageAttachmentPath = $outputDirectory . '/original-attachment.jpg';
$imageAttachmentPayload = file_get_contents($root . '/4fsym/br.jpg');
if (
    !is_string($imageAttachmentPayload)
    || $imageAttachmentPayload === ''
    || file_put_contents($imageAttachmentPath, $imageAttachmentPayload)
        !== strlen($imageAttachmentPayload)
) {
    throw new RuntimeException('Could not write visible image attachment');
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
$completeImageAttachment = $completeDossier->embedAttachment(
    $imageAttachmentPath,
    'Render-Foto.jpg',
    'image/jpeg',
    'Sichtbarer Render-Nachweis einer JPEG-Anlage',
    hash('sha256', $imageAttachmentPayload),
    strlen($imageAttachmentPayload)
);
$etbRows = [[
    'estab_book_lfd' => 1,
    'estab_shift_id' => 17,
    'etb_lfd-nr' => 9001,
    'etb_time' => '2026-07-29 08:00:00',
    'estab_event_time' => '2026-07-29 07:59:30.000000',
    'estab_recorded_at' => '2026-07-29 08:00:00.000000',
    'estab_event_type' => 'message_reference',
    'estab_message_id' => 1,
    'estab_attachment_id' => 4,
    'estab_reference' => 'Lagekarte Abschnitt Nord',
    'estab_assignment' => 'ZUORDNUNG-NUR-SUCHHILFE',
    'estab_correction_of' => null,
    'etb_aktion' => 'EVENTSPALTE Produktionsnaher Layoutwechsel ETB',
    'etb_bemerk' => 'BEMERKUNGSSPALTE Vor der Nachrichtenvorlage',
    'etb_benutzer' => 'Rendernachweis',
    'etb_kuerzel' => 'REN001',
    'etb_funktion' => 'S2',
], [
    'estab_book_lfd' => 2,
    'estab_shift_id' => 17,
    'etb_lfd-nr' => 9002,
    'etb_time' => '2026-07-29 08:01:00',
    'estab_event_time' => '2026-07-29 08:00:30.000000',
    'estab_recorded_at' => '2026-07-29 08:01:00.000000',
    'estab_event_type' => 'ereignis',
    'estab_message_id' => null,
    'estab_attachment_id' => null,
    'estab_reference' => null,
    'estab_correction_of' => null,
    'etb_aktion' => str_repeat(
        "Mehrseitiger ETB-Eintrag mit vollständig wiederholtem Formkopf.\n",
        78
    ) . 'ETB-LANGTEXT-ENDE',
    'etb_bemerk' => 'Fortsetzungsnachweis',
    'etb_benutzer' => 'Rendernachweis',
    'etb_kuerzel' => 'REN001',
    'etb_funktion' => 'ETB',
]];
$tbbRows = [[
    'estab_book_lfd' => 1,
    'estab_shift_id' => 17,
    'tbb_lfd-nr' => 9101,
    'tbb_time' => '2026-07-29 08:05:00',
    'estab_event_time' => '2026-07-29 08:04:30.000000',
    'estab_recorded_at' => '2026-07-29 08:05:00.000000',
    'estab_entry_type' => 'betrieb_personal',
    'estab_personnel_duty' => 'DIENSTSPALTE LdF und Fernmelder im Dienst',
    'estab_channel' => 'KANALSPALTE THW 1',
    'estab_verbindungszustand' => 'betriebsbereit',
    'estab_message_route' =>
        'NACHRICHTVON Leitstelle an NACHRICHTAN Führungsstelle',
    'estab_message_id' => 1,
    'estab_operations' =>
        'BETRIEBSSPALTE Produktionsnaher Layoutwechsel TBB',
    'estab_stoerung_abstellung' => 'keine Störung',
    'estab_receipt' => 'QUITTUNGSSPALTE REN002',
    'estab_correction_of' => null,
    'tbb_aktion' => 'Kompatibilitätszusammenfassung nicht erneut drucken',
    'tbb_bemerk' => 'Zusatzbemerkung genau einmal drucken',
    'tbb_benutzer' => 'Rendernachweis',
    'tbb_kuerzel' => 'REN002',
    'tbb_funktion' => 'A/W',
], [
    'estab_book_lfd' => 2,
    'estab_shift_id' => 17,
    'tbb_lfd-nr' => 9102,
    'tbb_time' => '2026-07-29 08:06:00',
    'estab_event_time' => '2026-07-29 08:05:30.000000',
    'estab_recorded_at' => '2026-07-29 08:06:00.000000',
    'estab_entry_type' => 'legacy_import',
    'tbb_aktion' => 'Legacy-Betriebsvorgang bleibt sichtbar',
    'tbb_bemerk' => 'Legacy-Bemerkung bleibt sichtbar',
    'tbb_benutzer' => 'Rendernachweis',
    'tbb_kuerzel' => 'REN002',
    'tbb_funktion' => 'A/W',
], [
    'estab_book_lfd' => 3,
    'estab_shift_id' => 17,
    'tbb_lfd-nr' => 9103,
    'tbb_time' => '2026-07-29 08:07:00',
    'estab_event_time' => '2026-07-29 08:06:30.000000',
    'estab_recorded_at' => '2026-07-29 08:07:00.000000',
    'estab_entry_type' => 'betriebsereignis',
    'estab_personnel_duty' => '',
    'estab_channel' => '',
    'estab_verbindungszustand' => '',
    'estab_message_route' => '',
    'estab_message_id' => null,
    'estab_operations' => str_repeat(
        "Mehrseitiger TBB-Eintrag im amtlichen Betriebsablauffeld.\n",
        82
    ) . 'TBB-LANGTEXT-ENDE',
    'estab_stoerung_abstellung' => '',
    'estab_receipt' => '',
    'estab_correction_of' => null,
    'tbb_aktion' => '',
    'tbb_bemerk' => 'Fortsetzungsnachweis',
    'tbb_benutzer' => 'Rendernachweis',
    'tbb_kuerzel' => 'REN002',
    'tbb_funktion' => 'A/W',
]];
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
    ],
    [
        'mode' => 'shift',
        'shift_id' => 17,
        'number' => 2,
        'name' => 'Nachtschicht Rendernachweis',
        'status' => 'UEBERGEBEN',
        'created_at' => '2026-07-29 17:00:00.000000',
        'activated_at' => '2026-07-29 18:00:00.000000',
        'ended_at' => '2026-07-30 06:00:00.000000',
    ]
);
$completeDossier->addLogbook('ETB', $etbRows);
$completeDossier->addLogbook('TBB', $tbbRows);
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
            '{"direction":"E",'
                . '"08_befhinweis":"DOSSIER-ALT-HINWEIS-NICHT-DRUCKEN",'
                . '"08_befhinwausw":"Fu",'
                . '"terminal_snapshot_sha256":"fixture"}',
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
$completeAttachmentIndex = [[
    'display_name' => 'ETB 1-1-1 · Render-Anlage.txt',
    'stored_name' => $completeAttachment['name'],
    'archive_name' => 'EL0001.txt',
    'size' => $completeAttachment['size'],
    'sha256' => $completeAttachment['sha256'],
    'mime' => $completeAttachment['mime'],
    'integrity_state' => 'verified',
    'integrity_statement' =>
        'SHA-256 und Größe entsprechen dem Eingangsnachweis',
    'etb_attachment_numbers' => ['ETB 1-1-1'],
    'message_ids' => [1],
], [
    'display_name' => 'Render-Foto.jpg · sichtbare Bildanlage',
    'stored_name' => $completeImageAttachment['name'],
    'archive_name' => 'EL0002.jpg',
    'size' => $completeImageAttachment['size'],
    'sha256' => $completeImageAttachment['sha256'],
    'mime' => $completeImageAttachment['mime'],
    'integrity_state' => 'verified',
    'integrity_statement' =>
        'SHA-256 und Größe entsprechen dem Eingangsnachweis',
    'etb_attachment_numbers' => [],
    'message_ids' => [1],
]];
$completeDossier->addAttachmentIndex($completeAttachmentIndex);
$completeDossier->addAttachmentPages($completeAttachmentIndex);
$completeDossierBytes = $completeDossier->Output('', 'S');

$etbForm = new EstabIncidentPdf($incident, 1024 * 1024, $matrix);
$etbForm->SetCompression(false);
$etbForm->addLogbook('ETB', $etbRows);
$etbFormBytes = $etbForm->Output('', 'S');

$tbbForm = new EstabIncidentPdf($incident, 1024 * 1024, $matrix);
$tbbForm->SetCompression(false);
$tbbForm->addLogbook('TBB', $tbbRows);
$tbbFormBytes = $tbbForm->Output('', 'S');

$crossShiftCorrectionForm = new EstabIncidentPdf(
    $incident,
    1024 * 1024,
    $matrix
);
$crossShiftCorrectionForm->SetCompression(false);
$crossShiftCorrectionForm->addLogbook('ETB', [array_replace($etbRows[1], [
    'etb_lfd-nr' => 987654,
    'estab_event_type' => 'korrektur',
    'estab_correction_of' => 876543,
    'estab_correction_book_lfd' => 7,
    'estab_reference' => '7',
    'etb_aktion' => 'ETB-KORREKTUR-AUS-ANDERER-SCHICHT',
    'etb_bemerk' => 'Original ist nicht Teil der gefilterten Zeilen',
])]);
$crossShiftCorrectionForm->addLogbook('TBB', [array_replace($tbbRows[1], [
    'tbb_lfd-nr' => 987655,
    'estab_entry_type' => 'korrektur',
    'estab_correction_of' => 876544,
    'estab_correction_book_lfd' => 8,
    'tbb_aktion' => 'TBB-KORREKTUR-AUS-ANDERER-SCHICHT',
    'tbb_bemerk' => 'Original ist nicht Teil der gefilterten Zeilen',
])]);
$crossShiftCorrectionFormBytes = $crossShiftCorrectionForm->Output('', 'S');

$closedIncident = array_replace($incident, [
    'estab_status' => 'closed',
    'ende' => '2026-07-29 18:00:00',
    'estab_closed_at' => '2026-07-29 18:00:00.000000',
    'estab_closed_by' => 'PDF-Rendernachweis',
    'estab_close_note' => 'Formaler Abschluss des Rendernachweises',
    'estab_retain_until' => '2036-07-29 18:00:00.000000',
]);
$closedEtbForm = new EstabIncidentPdf(
    $closedIncident,
    1024 * 1024,
    $matrix
);
$closedEtbForm->SetCompression(false);
$closedEtbForm->addLogbook('ETB', [array_replace($etbRows[0], [
    'etb_aktion' => 'Einsatztagebuch formal abgeschlossen.',
    'etb_bemerk' => 'Abschluss nach vollständiger Prüfung',
])]);
$closedEtbFormBytes = $closedEtbForm->Output('', 'S');

$closedTbbForm = new EstabIncidentPdf(
    $closedIncident,
    1024 * 1024,
    $matrix
);
$closedTbbForm->SetCompression(false);
$closedTbbForm->addLogbook('TBB', [array_replace($tbbRows[0], [
    'estab_personnel_duty' => '',
    'estab_channel' => '',
    'estab_message_route' => '',
    'estab_message_id' => null,
    'estab_operations' => 'Technisches Betriebsbuch formal abgeschlossen.',
    'estab_receipt' => 'LdF Rendernachweis',
    'tbb_aktion' => '',
    'tbb_bemerk' => '',
])]);
$closedTbbFormBytes = $closedTbbForm->Output('', 'S');

$maximumIncident = array_replace($incident, [
    'kennung' => 'MAX-' . str_repeat('K', 60),
    'name' => str_repeat('N', ESTAB_INCIDENT_NAME_MAX_LENGTH),
    'fuehrungsstellenname' => str_repeat(
        'F',
        ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH
    ),
]);
$maximumHeaderDossier = new EstabIncidentPdf(
    $maximumIncident,
    1024 * 1024,
    $matrix
);
$maximumHeaderDossier->SetCompression(false);
$maximumHeaderDossier->addCover(
    $maximumIncident,
    [],
    [],
    '29.07.2026 08:30:00 CEST',
    'PDF-Maximalwert-Rendernachweis'
);
$maximumHeaderDossierBytes = $maximumHeaderDossier->Output('', 'S');

foreach (array_merge([
    'message-form.pdf' => $singleBytes,
    'dossier-message-form.pdf' => $dossierBytes,
    'long-message-form.pdf' => $longSingleBytes,
    'dossier-long-message-form.pdf' => $longDossierBytes,
    'etb-form.pdf' => $etbFormBytes,
    'tbb-form.pdf' => $tbbFormBytes,
    'cross-shift-correction.pdf' => $crossShiftCorrectionFormBytes,
    'etb-form-closed.pdf' => $closedEtbFormBytes,
    'tbb-form-closed.pdf' => $closedTbbFormBytes,
    'dossier-all.pdf' => $completeDossierBytes,
    'dossier-maximum-header.pdf' => $maximumHeaderDossierBytes,
], $priorityDocuments, $mediumDocuments) as $filename => $bytes) {
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
