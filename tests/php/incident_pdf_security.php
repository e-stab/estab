<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/incident_pdf.php';
require_once __DIR__ . '/pdf_test_fixture.php';

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
$assertThrows = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (EstabIncidentPdfInputException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$temporaryRoot = sys_get_temp_dir()
    . '/estab-incident-pdf-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryRoot, 0700)) {
    throw new RuntimeException('Could not create PDF test directory');
}
$attachmentPath = $temporaryRoot . '/EL0001.txt';
$attachmentPayload = "Originalanlage\n";
file_put_contents($attachmentPath, $attachmentPayload);
chmod($attachmentPath, 0600);

try {
    $incident = [
        'kennung' => 'EL-2026-001',
        'name' => 'Sturm Münster',
        'beginn' => '2026-07-29 10:30:00',
        'ende' => null,
        'ort' => 'Münster',
        'organisation' => 'Kreis',
        'fuehrungsstellenname' => 'FueSt-Sued-42',
        'einsatzleitung' => 'Max Beispiel',
        'beschreibung' => 'Vollständiger Funktionsnachweis',
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
    $recipientMatrix = estab_pdf_test_recipient_matrix();
    $pdf = new EstabIncidentPdf(
        $incident,
        1024 * 1024,
        $recipientMatrix
    );
    $pdf->SetCompression(false);
    $embedded = $pdf->embedAttachment(
        $attachmentPath,
        'Anlage-1-EL0001.txt',
        'text/plain',
        'Originalanlage zu Nachricht E 1',
        hash('sha256', $attachmentPayload),
        strlen($attachmentPayload)
    );
    $pdf->addCover(
        $incident,
        [
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
        '29.07.2026 12:00:00',
        'estab-admin',
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
    $pdf->addLogbook('ETB', [[
        'etb_lfd-nr' => 1,
        'etb_time' => '2026-07-29 10:31:00',
        'estab_event_time' => '2026-07-29 10:30:30.000000',
        'estab_recorded_at' => '2026-07-29 10:31:00.000000',
        'estab_event_type' => 'ereignis',
        'estab_message_id' => 7,
        'estab_attachment_id' => 4,
        'estab_reference' => 'Lagekarte A',
        'estab_correction_of' => null,
        'etb_aktion' => 'Einsatz eröffnet',
        'etb_bemerk' => 'Führungsstelle besetzt',
        'etb_benutzer' => 'Ada Beispiel',
        'etb_kuerzel' => 'ADA001',
        'etb_funktion' => 'S2',
    ]]);
    // Accept the short-lived TTB spelling as an input alias, but render TBB.
    $pdf->addLogbook('TTB', [[
        'tbb_lfd-nr' => 1,
        'tbb_time' => '2026-07-29 10:32:00',
        'tbb_aktion' => 'Funkkanal eingerichtet',
        'tbb_bemerk' => '',
        'tbb_benutzer' => 'Berta Beispiel',
        'tbb_kuerzel' => 'BER001',
        'tbb_funktion' => 'A/W',
    ]]);
    $message = array_replace(estab_pdf_test_message_fixture(), [
        '00_lfd' => 7,
        'einsatz_id' => 12,
        '04_richtung' => 'E',
        '04_nummer' => 1,
        '01_medium' => 'Fu',
        '01_datum' => '2026-07-29 10:33:00',
        '01_zeichen' => 'BER001',
        '02_zeit' => null,
        '03_datum' => null,
        '05_gegenstelle' => 'Leitstelle',
        '06_befweg' => 'Funk',
        '10_anschrift' => 'Einsatzleitung',
        '12_anhang' => 'EL0001.txt;',
        '12_abfzeit' => '2026-07-29 10:32:00',
        '12_inhalt' => 'Sturmschaden &amp; Straßensperrung',
        '13_abseinheit' => 'Leitstelle',
        '14_zeichen' => 'BER001',
        '14_funktion' => 'A/W',
        '15_quitdatum' => null,
        '17_vermerke' => 'Bestätigt',
    ]);
    $pdf->addMessages([$message], [7 => ['EL0001.txt']]);
    $pdf->addMessageEvidence(
        [[
            'event_id' => 10,
            'einsatz_id' => 12,
            'message_id' => 7,
            'event_type' => 'incoming_routed',
            'occurred_at' => '2026-07-29 10:34:00.000000',
            'recorded_at' => '2026-07-29 10:34:01.000000',
            'actor_user' => 'Sichter Beispiel',
            'actor_code' => 'SICHT1',
            'actor_function' => 'Si',
            'from_status' => 4,
            'to_status' => 8,
            'field_snapshot' => '{"terminal_snapshot_sha256":"fixture"}',
            'snapshot_sha256' => str_repeat('c', 64),
            'previous_event_sha256' => null,
            'event_sha256' => str_repeat('d', 64),
        ]],
        [[
            'message_id' => 7,
            'einsatz_id' => 12,
            'event_count' => 1,
            'last_event_sha256' => str_repeat('d', 64),
            'updated_at' => '2026-07-29 10:34:01.000000',
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
    $pdf->addDutyRecords(
        [[
            'dienstschicht_id' => 21,
            'einsatz_id' => 12,
            'nummer' => 1,
            'bezeichnung' => 'Tagschicht',
            'status' => 'UEBERGEBEN',
            'vorgaenger_id' => null,
            'erstellt_am' => '2026-07-29 08:00:00.000000',
            'erstellt_von' => 'admin',
            'aktiviert_am' => '2026-07-29 08:10:00.000000',
            'beendet_am' => '2026-07-29 16:00:00.000000',
        ]],
        [[
            'dienstbesetzung_id' => 31,
            'dienstschicht_id' => 21,
            'dienstschicht_nummer' => 1,
            'benutzer_kuerzel' => 'SICHT1',
            'funktion' => 'Si',
            'rolle' => 'Stab',
            'status' => 'ABGELOEST',
            'zugewiesen_am' => '2026-07-29 08:01:00.000000',
            'zugewiesen_von' => 'admin',
            'angenommen_am' => '2026-07-29 08:02:00.000000',
            'abgeloest_am' => '2026-07-29 16:00:00.000000',
            'nachfolger_id' => 32,
        ]],
        [[
            'dienstuebergabe_id' => 41,
            'einsatz_id' => 12,
            'von_dienstschicht_id' => 21,
            'an_dienstschicht_id' => 22,
            'zusammenfassung' => 'Offene Punkte vollständig übergeben',
            'uebergeben_am' => '2026-07-29 16:00:00.000000',
            'uebergeben_von' => 'admin',
            'angenommen_von' => 'SICHT2',
        ]],
        [[
            'dienstuebergabe_anfrage_id' => 42,
            'einsatz_id' => 12,
            'von_dienstschicht_id' => 21,
            'an_dienstschicht_id' => 22,
            'zusammenfassung' => 'Bestätigung der Nachfolge steht aus',
            'status' => 'INITIIERT',
            'initiiert_am' => '2026-07-29 15:55:00.000000',
            'initiiert_von' => 'admin',
            'bestaetigt_am' => null,
            'bestaetigt_von' => null,
            'bestaetigt_mit_besetzung_id' => null,
            'dienstuebergabe_id' => null,
            'storniert_am' => null,
            'storniert_von' => null,
            'stornierungsgrund' => null,
        ], [
            'dienstuebergabe_anfrage_id' => 43,
            'einsatz_id' => 12,
            'von_dienstschicht_id' => 21,
            'an_dienstschicht_id' => 22,
            'zusammenfassung' => 'Fehlanforderung',
            'status' => 'STORNIERT',
            'initiiert_am' => '2026-07-29 15:56:00.000000',
            'initiiert_von' => 'admin',
            'bestaetigt_am' => null,
            'bestaetigt_von' => null,
            'bestaetigt_mit_besetzung_id' => null,
            'dienstuebergabe_id' => null,
            'storniert_am' => '2026-07-29 15:57:00.000000',
            'storniert_von' => 'admin',
            'stornierungsgrund' => 'Falsche Nachfolgeschicht',
        ], [
            'dienstuebergabe_anfrage_id' => 44,
            'einsatz_id' => 12,
            'von_dienstschicht_id' => 21,
            'an_dienstschicht_id' => 22,
            'zusammenfassung' => 'Persönlich bestätigte Übergabe',
            'status' => 'BESTAETIGT',
            'initiiert_am' => '2026-07-29 15:58:00.000000',
            'initiiert_von' => 'admin',
            'bestaetigt_am' => '2026-07-29 16:00:00.000000',
            'bestaetigt_von' => 'SICHT2',
            'bestaetigt_mit_besetzung_id' => 32,
            'dienstuebergabe_id' => 41,
            'storniert_am' => null,
            'storniert_von' => null,
            'stornierungsgrund' => null,
        ]]
    );
    $pdf->addS6Plans(
        [[
            'fernmeldeplan_id' => 51,
            'einsatz_id' => 12,
            'version' => 2,
            'status' => 'AKTIV',
            'einsatzbezeichnung' => 'Sturm Münster',
            'herkunft' => 'S6 Führungsstelle',
            'gueltig_ab' => '2026-07-29 10:00:00',
            'gueltig_bis' => '2026-07-30 10:00:00',
            'betriebsleitung' => 'LdF',
            'bemerkungen' => 'Freigegebene Funkplanung',
            'erstellt_am' => '2026-07-29 09:30:00.000000',
            'erstellt_von' => 'S60001',
            'freigegeben_am' => '2026-07-29 09:45:00.000000',
            'freigegeben_von' => 'S60001',
        ]],
        [[
            'fernmeldeplan_eintrag_id' => 61,
            'fernmeldeplan_id' => 51,
            'plan_version' => 2,
            'sortierung' => 1,
            'betriebsstelle' => 'Leitstelle',
            'rufname' => 'Florian Leitstelle',
            'medium' => 'Fu',
            'kanal' => 'Kanal 4',
            'bandlage' => '2 m',
            'verkehrsform' => 'Sternverkehr',
            'besondere_vermerke' => 'Vorrang',
            'bemerkungen' => 'Stündliche Funkprobe',
        ]]
    );
    $pdf->addCourierOrders([[
        'melderauftrag_id' => 71,
        'einsatz_id' => 12,
        'nachricht_id' => 7,
        'melder_kuerzel' => 'MELD01',
        'ziel' => 'Leitstelle',
        'status' => 'GEMELDET',
        'beauftragt_am' => '2026-07-29 10:35:00.000000',
        'beauftragt_von' => 'LDF001',
        'uebernommen_am' => '2026-07-29 10:36:00.000000',
        'tatsaechlicher_empfaenger' => 'Frau Zielperson',
        'uebergeben_am' => '2026-07-29 10:45:00.000000',
        'ruecknachricht_vorhanden' => true,
        'ruecknachricht' => 'Empfang bestätigt',
        'rueckweg_am' => '2026-07-29 10:46:00.000000',
        'zurueck_am' => '2026-07-29 10:55:00.000000',
        'abschlussvermerk' => 'Rückkehr gemeldet',
        'gemeldet_am' => '2026-07-29 10:56:00.000000',
        'gemeldet_an' => 'AW0001',
        'abgebrochen_am' => null,
        'abbruchgrund' => null,
    ]]);
    $pdf->addOperationsEvidence(
        [[
            'betriebsereignis_id' => 81,
            'einsatz_id' => 12,
            'sequenz' => 1,
            'objekttyp' => 'MELDERAUFTRAG',
            'objekt_id' => 71,
            'aktion' => 'melderauftrag_gemeldet',
            'akteur_kuerzel' => 'AW0001',
            'akteur_funktion' => 'A/W',
            'ereigniszeit' => '2026-07-29 10:56:00.000000',
            'details_json' => '{"status":"GEMELDET"}',
            'vorheriger_hash' => str_repeat('0', 64),
            'ereignis_hash' => str_repeat('b', 64),
        ]],
        [[
            'einsatz_id' => 12,
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
    $pdf->addAttachmentIndex([[
        'display_name' => 'EL0001.txt · lage.txt',
        'stored_name' => $embedded['name'],
        'size' => $embedded['size'],
        'sha256' => $embedded['sha256'],
        'mime' => $embedded['mime'],
        'integrity_state' => 'verified',
        'integrity_statement' =>
            'SHA-256 und Größe entsprechen dem Eingangsnachweis',
        'message_ids' => [7],
    ]]);
    $document = $pdf->Output('', 'S');
    $fixtureOutput = getenv('ESTAB_INCIDENT_PDF_FIXTURE_OUTPUT');
    if (is_string($fixtureOutput) && $fixtureOutput !== '') {
        if (
            !str_starts_with($fixtureOutput, '/')
            || !is_dir(dirname($fixtureOutput))
            || file_put_contents($fixtureOutput, $document) !== strlen($document)
        ) {
            throw new RuntimeException('Could not write requested PDF fixture');
        }
    }

    $assert(str_starts_with($document, '%PDF-1.7'), 'PDF version/header missing');
    $assert(
        str_contains($document, 'Funkkanal eingerichtet'),
        'TBB columns were not mapped into the PDF'
    );
    $assert(
        str_contains($document, 'TBB 1')
            && !str_contains($document, 'TTB 1'),
        'legacy TTB input alias was not rendered with the canonical TBB label'
    );
    $assert(str_ends_with($document, "%%EOF\n"), 'PDF trailer missing');
    $assert(strlen($document) > 5000, 'incident PDF is unexpectedly small');
    foreach ([
        'VORL',
        'Nachrichten-Head-Summenhash',
        'Ereigniszeit',
        'Nachrichtenereignis',
        'Dienstschicht',
        'S6-Fernmeldeplan Version',
        'Melderauftrag',
        'cknachricht vorhanden',
        'bergabeanforderung',
        'INITIIERT',
        'STORNIERT',
        'BESTAETIGT',
        'Betriebsereignis Sequenz',
        'Aufbewahrung bis',
        'Legal Hold',
        'FueSt-Sued-42',
    ] as $marker) {
        $assert(
            str_contains($document, $marker),
            'DV dossier marker is missing: ' . $marker
        );
    }
    foreach (
        ['EINGANG', 'AUSGANG', 'Nachweis-Nr.', 'Fm-Betriebsstelle']
        as $marker
    ) {
        $assert(
            str_contains($document, $marker),
            'incident PDF message form marker is missing: ' . $marker
        );
    }
    $assert(
        !str_contains($document, 'Dienstgebrauch')
            && !str_contains($document, 'VS-NfD'),
        'incident PDF message form still contains a VS marking'
    );
    $assert(
        !str_contains($document, '/Subtype /Image'),
        'incident PDF message form still contains the coat of arms'
    );
    $assert(
        !str_contains($document, '/4fach/download.php')
            && !str_contains($document, '/URI '),
        'historical dossier contains an active-incident attachment link'
    );
    $assert(
        str_contains($document, 'ALT_1 [gn]')
            && str_contains($document, 'ALT2 [rt]'),
        'historical recipients outside the current matrix are invisible'
    );
    $assert(
        str_contains($document, '/EmbeddedFiles')
            && str_contains($document, '/Type /Filespec')
            && str_contains($document, '/Type /EmbeddedFile')
            && str_contains($document, '/AFRelationship /Data')
            && str_contains($document, '/PageMode /UseAttachments'),
        'embedded-file catalog contract is incomplete'
    );
    $assert(
        str_contains($document, '(Anlage-1-EL0001.txt)')
            && str_contains($document, md5("Originalanlage\n"))
            && str_contains($document, "Originalanlage\n"),
        'embedded attachment bytes, name, or checksum are missing'
    );
    $assert(
        !str_contains($document, $temporaryRoot),
        'host attachment path leaked into the PDF'
    );
    $assert(
        $pdf->embeddedAttachmentCount() === 1
            && $pdf->embeddedAttachmentBytes() === strlen("Originalanlage\n"),
        'embedded attachment counters are incorrect'
    );
    $assert(
        $embedded['sha256'] === hash('sha256', "Originalanlage\n"),
        'embedded attachment SHA-256 differs'
    );
    $assertThrows(
        static fn (): array => estab_incident_pdf_read_attachment(
            $attachmentPath,
            1024,
            str_repeat('0', 64),
            strlen($attachmentPayload)
        ),
        'PDF attachment read accepted a mismatching ingest SHA-256'
    );

    $closedIncident = array_replace($incident, [
        'estab_status' => 'closed',
        'estab_closed_at' => '2026-07-30 18:00:00.000000',
        'estab_closed_by' => 'estab-admin',
        'estab_close_note' => 'Abschlussprüfung vollständig',
        'estab_retain_until' => '2027-07-30 18:00:00.000000',
        'estab_legal_hold' => 1,
        'estab_legal_hold_reason' => 'Behördliche Nachprüfung',
        'estab_legal_hold_at' => '2026-07-30 18:05:00.000000',
        'estab_legal_hold_by' => 'estab-admin',
    ]);
    $closedPdf = new EstabIncidentPdf($closedIncident, 1024);
    $closedPdf->SetCompression(false);
    $closedPdf->addCover(
        $closedIncident,
        ['etb'],
        ['etb' => 0],
        '30.07.2026 18:06:00',
        'estab-admin',
        [
            'message' => [
                'valid' => true,
                'event_count' => 0,
                'head_count' => 0,
                'head_mismatches' => 0,
                'head_set_sha256' => hash('sha256', ''),
                'terminal_binding_complete' => true,
                'terminal_unverifiable' => 0,
            ],
            'operations' => [
                'valid' => true,
                'event_count' => 0,
                'stored_sequence' => 0,
                'stored_head_sha256' => str_repeat('0', 64),
                'calculated_head_sha256' => str_repeat('0', 64),
            ],
        ]
    );
    $closedDocument = $closedPdf->Output('', 'S');
    $assert(
        str_contains($closedDocument, 'FORMAL ABGESCHLOSSEN')
            && str_contains($closedDocument, '2027-07-30 18:00:00.000000')
            && str_contains($closedDocument, 'FueSt-Sued-42')
            && str_contains($closedDocument, 'AKTIV'),
        'Closed cover omits command post, formal status, retention, or legal hold'
    );

    $historicalIncident = array_replace($incident, [
        'fuehrungsstellenname' => null,
    ]);
    $historicalPdf = new EstabIncidentPdf($historicalIncident, 1024);
    $historicalPdf->SetCompression(false);
    $historicalPdf->addCover(
        $historicalIncident,
        [],
        [],
        '30.07.2026 18:06:00',
        'estab-admin'
    );
    $historicalDocument = $historicalPdf->Output('', 'S');
    $assert(
        str_contains(
            $historicalDocument,
            estab_incident_pdf_text(
                'Führungsstelle historisch nicht erfasst'
            )
        )
            && str_contains(
                $historicalDocument,
                'historisch nicht erfasst'
            ),
        'historical PDF silently invents or omits a command-post identity'
    );

    $assertThrows(
        static fn (): string => estab_incident_pdf_attachment_name('../x.pdf'),
        'traversal attachment name accepted'
    );
    $assertThrows(
        static fn (): string => estab_incident_pdf_mime_name('text/plain;evil'),
        'parameterized MIME token accepted'
    );
    $assertThrows(
        static fn (): array => estab_incident_pdf_read_attachment(
            $temporaryRoot . '/missing',
            1024
        ),
        'missing attachment accepted'
    );
    $assertThrows(
        static fn (): array => estab_incident_pdf_read_attachment(
            $attachmentPath,
            1
        ),
        'oversize attachment accepted'
    );
    $linkPath = $temporaryRoot . '/link.txt';
    if (!symlink($attachmentPath, $linkPath)) {
        throw new RuntimeException('Could not create attachment symlink');
    }
    $assertThrows(
        static fn (): array => estab_incident_pdf_read_attachment(
            $linkPath,
            1024
        ),
        'attachment symlink accepted'
    );

    $longMessagePdf = new EstabIncidentPdf(
        $incident,
        1024,
        $recipientMatrix
    );
    $longMessagePdf->SetCompression(false);
    $longMessage = array_replace(estab_pdf_test_message_fixture(), [
        '00_lfd' => 8,
        'einsatz_id' => 12,
        '12_inhalt' => str_repeat(
            "Mehrseitiger Formularinhalt\n",
            45
        ) . 'ENDE-MEHRSEITIGER-VORDRUCK',
    ]);
    $longMessagePdf->addMessages([$longMessage], [8 => []]);
    $longDocument = $longMessagePdf->Output('', 'S');
    $assert(
        $longMessagePdf->PageNo() > 1
            && str_contains($longDocument, 'ENDE-MEHRSEITIGER-VORDRUCK')
            && substr_count($longDocument, 'EINGANG')
                === $longMessagePdf->PageNo(),
        'long message did not continue across complete form-template pages'
    );

    $duplicatePdf = new EstabIncidentPdf($incident, 1024);
    $duplicatePdf->embedAttachment(
        $attachmentPath,
        'Anlage.txt',
        'text/plain',
        ''
    );
    $assertThrows(
        static fn (): array => $duplicatePdf->embedAttachment(
            $attachmentPath,
            'Anlage.txt',
            'text/plain',
            ''
        ),
        'duplicate embedded attachment name accepted'
    );
} finally {
    foreach (scandir($temporaryRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        @unlink($temporaryRoot . '/' . $entry);
    }
    @rmdir($temporaryRoot);
}

echo "incident PDF security: OK ({$assertions} assertions)\n";
