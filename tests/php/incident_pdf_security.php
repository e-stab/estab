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

/** Return the exact implementation text of one dossier method. */
function incident_pdf_security_method_source(string $method): string
{
    $reflection = new ReflectionMethod(EstabIncidentPdf::class, $method);
    $lines = file($reflection->getFileName());
    if (!is_array($lines)) {
        throw new RuntimeException('Could not read incident PDF implementation');
    }
    return implode('', array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
}

/** @return list<string> */
function incident_pdf_security_embedded_streams(string $pdf): array
{
    $matched = preg_match_all(
        '/\/Type \/EmbeddedFile\b.*?\/Length ([0-9]+)\s+'
            . '.*?stream\r?\n/s',
        $pdf,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    if (!is_int($matched) || $matched < 1) {
        return [];
    }
    $streams = [];
    foreach ($matches as $match) {
        $length = (int) ($match[1][0] ?? -1);
        $matchedText = (string) ($match[0][0] ?? '');
        $offset = (int) ($match[0][1] ?? -1) + strlen($matchedText);
        $stream = $length >= 0 && $offset >= 0
            ? substr($pdf, $offset, $length)
            : '';
        if (strlen($stream) !== $length) {
            throw new RuntimeException('Embedded PDF file stream is truncated');
        }
        $streams[] = $stream;
    }
    return $streams;
}

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
    $rasterSource = incident_pdf_security_method_source(
        'rasterizePdfAttachment'
    );
    $attachmentPagesSource = incident_pdf_security_method_source(
        'addAttachmentPages'
    );
    $imageInfoSource = incident_pdf_security_method_source(
        'attachmentImageInfo'
    );
    $assert(
        ESTAB_INCIDENT_PDF_RENDER_TOTAL_SECONDS === 60
            && ESTAB_INCIDENT_PDF_PROCESS_PAGE_SECONDS === 15
            && str_contains(
                $attachmentPagesSource,
                'ESTAB_INCIDENT_PDF_RENDER_TOTAL_SECONDS'
            )
            && substr_count(
                $rasterSource,
                'ESTAB_INCIDENT_PDF_PROCESS_PAGE_SECONDS'
            ) === 2,
        '60-second total and 15-second per-process renderer limits drifted'
    );
    $assert(
        ESTAB_INCIDENT_PDF_MAX_RASTER_BYTES === 24 * 1024 * 1024
            && ESTAB_INCIDENT_PDF_MAX_RASTER_PAGE_BYTES === 8 * 1024 * 1024
            && str_contains(
                $attachmentPagesSource,
                'ESTAB_INCIDENT_PDF_MAX_RASTER_BYTES'
            )
            && str_contains(
                $rasterSource,
                'ESTAB_INCIDENT_PDF_MAX_RASTER_PAGE_BYTES'
            )
            && str_contains($rasterSource, '$totalBytes + $bytes')
            && str_contains($rasterSource, '$remainingRasterBytes'),
        '24-MiB total or 8-MiB per-page raster limits drifted or became unused'
    );
    $assert(
        ESTAB_INCIDENT_PDF_MAX_IMAGE_PIXELS === 12_000_000
            && ESTAB_INCIDENT_PDF_MAX_IMAGE_AXIS === 8_000
            && str_contains(
                $imageInfoSource,
                'ESTAB_INCIDENT_PDF_MAX_IMAGE_PIXELS'
            )
            && substr_count(
                $imageInfoSource,
                'ESTAB_INCIDENT_PDF_MAX_IMAGE_AXIS'
            ) === 2,
        '12-megapixel or 8000-pixel image limits drifted or became unused'
    );
    $assert(
        str_contains($rasterSource, 'for ($pageNumber = 1;')
            && str_contains($rasterSource, "'-f'")
            && str_contains($rasterSource, "'-l'")
            && str_contains($rasterSource, "'-singlefile'")
            && !str_contains($rasterSource, 'hide-annotations'),
        'PDF pages are no longer rastered singly or annotations are suppressed'
    );
    $assert(
        str_contains(
            estab_incident_pdf_logbook_scope_label([]),
            'Gesamtbuch'
        ),
        'Default logbook scope is not the complete book'
    );
    $assertThrows(
        static fn (): string => estab_incident_pdf_logbook_scope_label([
            'mode' => 'shift',
            'shift_id' => 1,
        ]),
        'Incomplete shift metadata was accepted for the PDF cover'
    );
    $shiftScopeLabel = estab_incident_pdf_logbook_scope_label([
        'mode' => 'shift',
        'shift_id' => 23,
        'number' => 2,
        'name' => 'Nachtschicht',
        'status' => 'UEBERGEBEN',
        'created_at' => '2026-07-29 17:00:00.000000',
        'activated_at' => '2026-07-29 18:00:00.000000',
        'ended_at' => '2026-07-30 06:00:00.000000',
    ]);
    $assert(
        str_contains($shiftScopeLabel, 'Nachtschicht')
            && str_contains($shiftScopeLabel, 'ID: 23')
            && str_contains($shiftScopeLabel, 'UEBERGEBEN')
            && str_contains($shiftScopeLabel, '2026-07-29 18:00:00')
            && str_contains($shiftScopeLabel, '2026-07-30 06:00:00'),
        'Shift scope label omits identity, status, or times'
    );
    $incident = [
        'einsatz_id' => 12,
        'kennung' => 'EL-2026-001',
        'name' => 'Sturm Münster',
        'beginn' => '2026-07-29 10:30:00',
        'ende' => null,
        'ort' => 'Münster',
        'organisation' => 'Kreis',
        'fuehrungsstellenname' => 'FueSt-Sued-42',
        'einsatzleitung' => 'Max Beispiel',
        'beschreibung' => 'Vollständiger Funktionsnachweis',
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
        ],
        [
            'mode' => 'shift',
            'shift_id' => 23,
            'number' => 2,
            'name' => 'Nachtschicht',
            'status' => 'UEBERGEBEN',
            'created_at' => '2026-07-29 17:00:00.000000',
            'activated_at' => '2026-07-29 18:00:00.000000',
            'ended_at' => '2026-07-30 06:00:00.000000',
        ]
    );
    $pdf->addLogbook('ETB', [[
        'estab_book_lfd' => 1,
        'etb_lfd-nr' => 9001,
        'etb_time' => '2026-07-29 10:31:00',
        'estab_event_time' => '2026-07-29 10:30:30.000000',
        'estab_recorded_at' => '2026-07-29 10:31:00.000000',
        'estab_event_type' => 'ereignis',
        'estab_message_id' => 7,
        'estab_attachment_id' => 4,
        'estab_reference' => 'Lagekarte A',
        'estab_assignment' => 'ZUORDNUNG-NICHT-IM-FORMBLATT',
        'estab_correction_of' => null,
        'etb_aktion' => 'Einsatz eröffnet · A/W (Fernmelder): Berta [BER001]',
        'etb_bemerk' => 'Führungsstelle besetzt',
        'etb_benutzer' => 'Ada Beispiel',
        'etb_kuerzel' => 'ADA001',
        'etb_funktion' => 'S2',
    ]]);
    // Accept the short-lived TTB spelling as an input alias, but render TBB.
    $pdf->addLogbook('TTB', [[
        'estab_book_lfd' => 1,
        'tbb_lfd-nr' => 9101,
        'tbb_time' => '2026-07-29 10:32:00',
        'estab_event_time' => '2026-07-29 10:31:30.000000',
        'estab_recorded_at' => '2026-07-29 10:32:00.000000',
        'estab_entry_type' => 'betrieb_personal',
        'estab_personnel_duty' => 'TBB-DIENSTSPALTE · '
            . 'A/W (Fernmelder): Berta [BER001]',
        'estab_channel' => 'TBB-KANALSPALTE',
        'estab_verbindungszustand' => 'betriebsbereit',
        'estab_message_route' => 'TBB-NACHRICHT-VON an TBB-NACHRICHT-AN',
        'estab_message_id' => 7,
        'estab_operations' => 'TBB-BETRIEBSSPALTE',
        'estab_stoerung_abstellung' => 'TBB-STOERUNGSSPALTE',
        'estab_receipt' => 'TBB-QUITTUNGSSPALTE',
        'estab_correction_of' => null,
        'tbb_aktion' => 'Kompatibilitätszusammenfassung TBB-DIENSTSPALTE '
            . 'TBB-KANALSPALTE TBB-BETRIEBSSPALTE',
        'tbb_bemerk' => 'Zusatzbemerkung genau einmal drucken',
        'tbb_benutzer' => 'Berta Beispiel',
        'tbb_kuerzel' => 'BER001',
        'tbb_funktion' => 'A/W',
    ], [
        'estab_book_lfd' => 2,
        'tbb_lfd-nr' => 9102,
        'tbb_time' => '2026-07-29 10:33:00',
        'estab_event_time' => '2026-07-29 10:33:00.000000',
        'estab_recorded_at' => '2026-07-29 10:33:01.000000',
        'estab_entry_type' => 'legacy_import',
        'tbb_aktion' => 'Legacy-Betriebsvorgang bleibt sichtbar',
        'tbb_bemerk' => 'Legacy-Bemerkung bleibt sichtbar',
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
            'field_snapshot' => '{"14_funktion":"A/W",'
                . '"terminal_snapshot_sha256":"fixture"}',
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
        ]],
        [[
            'zugangsschicht_id' => 51,
            'einsatz_id' => 12,
            'bezeichnung' => 'Nachtschicht',
            'beginn' => '2026-07-29 20:00:00.000000',
            'ende' => '2026-07-30 08:00:00.000000',
            'zugang_aktiv' => 1,
            'erstellt_am' => '2026-07-29 18:00:00.000000',
            'erstellt_von' => 'admin',
            'geaendert_am' => '2026-07-29 20:00:00.000000',
            'geaendert_von' => 'admin',
        ]],
        [[
            'zugangsschicht_mitglied_id' => 61,
            'zugangsschicht_id' => 51,
            'schichtbezeichnung' => 'Nachtschicht',
            'benutzer_kuerzel' => 'SICHT1',
            'zugeordnet_am' => '2026-07-29 18:05:00.000000',
            'zugeordnet_von' => 'admin',
            'entfernt_am' => null,
            'entfernt_von' => null,
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
            'details_json' => '{"status":"GEMELDET",'
                . '"target_function":"A/W"}',
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
    $attachmentIndex = [[
        'display_name' => 'EL0001.txt · lage.txt',
        'stored_name' => $embedded['name'],
        'archive_name' => 'EL0001.txt',
        'size' => $embedded['size'],
        'sha256' => $embedded['sha256'],
        'mime' => $embedded['mime'],
        'integrity_state' => 'verified',
        'integrity_statement' =>
            'SHA-256 und Größe entsprechen dem Eingangsnachweis',
        'etb_attachment_numbers' => ['ETB 12-1-1'],
        'message_ids' => [7],
    ]];
    $pdf->addAttachmentIndex($attachmentIndex);
    $attachmentVisibility = $pdf->addAttachmentPages($attachmentIndex);
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
        str_contains(
            $document,
            estab_incident_pdf_text('Berechtigungsmodus')
        )
            && str_contains($document, 'Streng'),
        'PDF cover omits the incident permission mode'
    );
    $assert(
        str_contains($document, 'Nur Dienstschicht 2')
            && str_contains($document, 'UEBERGEBEN'),
        'Shift-filter metadata is missing from the dossier cover'
    );
    $assert(
        !str_contains($document, 'ZUORDNUNG-NICHT-IM-FORMBLATT'),
        'ETB search assignment leaked into the official form layout'
    );
    $assert(
        !str_contains($document, 'Nachricht: #7'),
        'A technical message primary key leaked into an official ETB/TBB column'
    );
    $assert(
        str_contains($document, 'Fb F')
            && str_contains($document, 'Einsatztagebuch')
            && str_contains($document, 'Technisches Betriebsbuch')
            && str_contains($document, 'Darstellung der Ereignisse')
            && str_contains($document, 'Fernmeldebetriebsstelle')
            && str_contains($document, 'Betriebsablauf/Ereignis')
            && str_contains($document, 'ETB-F')
            && str_contains($document, 'Fernmeldebetrieb')
            && str_contains($document, 'LdF')
            && str_contains($document, 'ETB 12-1-1')
            && str_contains($document, 'ETB-Anlagennummer')
            && str_contains($document, 'Ablagekennzeichen'),
        'official ETB/TBB form heads or signature fields are missing'
    );
    $assert(
        str_contains($document, 'TBB-DIENSTSPALTE')
            && str_contains($document, 'TBB-KANALSPALTE')
            && str_contains($document, 'TBB-NACHRICHT-VON')
            && str_contains($document, 'TBB-NACHRICHT-AN')
            && str_contains($document, 'TBB-BETRIEBSSPALTE')
            && str_contains($document, 'TBB-STOERUNGSSPALTE')
            && str_contains($document, 'TBB-QUITTUNGSSPALTE')
            && substr_count($document, 'TBB-DIENSTSPALTE') === 1
            && substr_count($document, 'TBB-KANALSPALTE') === 1
            && substr_count($document, 'TBB-BETRIEBSSPALTE') === 1
            && substr_count(
                $document,
                'Zusatzbemerkung genau einmal drucken'
            ) === 1
            && str_contains(
                $document,
                estab_incident_pdf_text(
                    'Legacy-Betriebsvorgang bleibt sichtbar'
                )
            )
            && str_contains($document, 'Legacy-Bemerkung bleibt sichtbar'),
        'structured or legacy TBB fields were not mapped into Fb Fü 44'
    );
    $assert(
        str_contains($document, '/MediaBox [0 0 841.89 595.28]')
            && !str_contains($document, '{etb#}')
            && !str_contains($document, '{tbb#}'),
        'TBB is not landscape or a logbook page alias leaked'
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
        str_contains($document, 'Fernmelder')
            && !str_contains($document, 'A/W'),
        'incident PDF does not consistently use the Fernmelder display label'
    );
    $messageOnlyPdf = new EstabIncidentPdf(
        $incident,
        1024 * 1024,
        $recipientMatrix
    );
    $messageOnlyPdf->SetCompression(false);
    $messageOnlyPdf->addMessages([$message], [7 => ['EL0001.txt']]);
    $messageOnlyDocument = $messageOnlyPdf->Output('', 'S');
    $assert(
        str_contains($messageOnlyDocument, 'Fernmelder')
            && !str_contains($messageOnlyDocument, 'A/W'),
        'single message PDF exposes the persisted function key'
    );
    $assert(
        !str_contains($messageOnlyDocument, '/Subtype /Image'),
        'incident PDF message form contains an unexpected image'
    );
    $assert(
        str_contains($document, '/Subtype /Image')
            && str_contains($document, '/Width 400')
            && str_contains($document, '/Height 396')
            && str_contains($document, '/BitsPerComponent 1'),
        'ETB/TBB form heads do not contain the existing THW gear mark'
    );
    $assert(
        str_contains($document, '0.125 0.271 0.541 rg')
            && str_contains($document, '0.251 0.216 0.537 rg'),
        'ETB blue and TBB violet title bands are not distinct'
    );
    $assert(
        substr_count($document, '0.745 0.769 0.804 RG') >= 2,
        'ETB/TBB writing grids are missing from the official form bodies'
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
        str_contains(
            $document,
            estab_incident_pdf_text('Vollständige Textdarstellung')
        )
            && str_contains($document, 'Sichtbare Darstellung')
            && $attachmentVisibility === [
                'attachment_visible_count' => 1,
                'attachment_visible_pages' => 1,
                'attachment_rendered_count' => 1,
                'attachment_rendered_pages' => 1,
                'attachment_information_pages' => 0,
            ],
        'plain-text attachment lacks its complete visible attachment page'
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

    $emailPath = __DIR__
        . '/../fixtures/email-multipart-xss-utf8.eml';
    $emailPayload = file_get_contents($emailPath);
    if (!is_string($emailPayload)) {
        throw new RuntimeException('Could not read PDF email fixture');
    }
    $emailPdf = new EstabIncidentPdf($incident, 1024 * 1024);
    $emailPdf->SetCompression(false);
    $emailEmbedded = $emailPdf->embedAttachment(
        $emailPath,
        'Anlage-E-Mail.eml',
        'message/rfc822',
        'Originale Einsatz-E-Mail',
        hash('sha256', $emailPayload),
        strlen($emailPayload)
    );
    $emailIndex = [[
        'display_name' => 'Einsatz-E-Mail.eml',
        'stored_name' => $emailEmbedded['name'],
        'archive_name' => 'EL0002.eml',
        'size' => $emailEmbedded['size'],
        'sha256' => $emailEmbedded['sha256'],
        'mime' => $emailEmbedded['mime'],
    ]];
    $emailPdf->addAttachmentIndex($emailIndex);
    $emailVisibility = $emailPdf->addAttachmentPages($emailIndex);
    $emailDocument = $emailPdf->Output('', 'S');
    $emailFixtureOutput = getenv(
        'ESTAB_INCIDENT_PDF_EMAIL_FIXTURE_OUTPUT'
    );
    if (is_string($emailFixtureOutput) && $emailFixtureOutput !== '') {
        if (
            !str_starts_with($emailFixtureOutput, '/')
            || !is_dir(dirname($emailFixtureOutput))
            || file_put_contents($emailFixtureOutput, $emailDocument)
                !== strlen($emailDocument)
        ) {
            throw new RuntimeException(
                'Could not write requested PDF email fixture'
            );
        }
    }
    $assert(
        $emailVisibility === [
            'attachment_visible_count' => 1,
            'attachment_visible_pages' => 1,
            'attachment_rendered_count' => 1,
            'attachment_rendered_pages' => 1,
            'attachment_information_pages' => 0,
        ]
            && str_contains(
                $emailDocument,
                estab_incident_pdf_text('Passive E-Mail-Darstellung')
            )
            && str_contains($emailDocument, 'E-MAIL-KOPFDATEN')
            && str_contains(
                $emailDocument,
                estab_incident_pdf_text(
                    'Von: Erika Müller <erika.mueller@example.test>'
                )
            )
            && str_contains(
                $emailDocument,
                estab_incident_pdf_text(
                    'An: Führungsstelle Göppingen '
                        . '<fuehrungsstelle@example.test>'
                )
            )
            && str_contains($emailDocument, 'Cc: \(nicht angegeben\)')
            && str_contains($emailDocument, 'Datum: Sat, 1 Aug 2026')
            && str_contains(
                $emailDocument,
                estab_incident_pdf_text('Betreff: Lage <Übung> – Grüße')
            )
            && str_contains($emailDocument, 'NACHRICHTENTEXT')
            && str_contains($emailDocument, 'E-Mail-Lagemeldung')
            && str_contains(
                $emailDocument,
                estab_incident_pdf_text(
                    'Gefahr & Rückmeldung aus der Übung.'
                )
            )
            && str_contains($emailDocument, 'EINGEBETTETE MAIL-DATEIEN')
            && str_contains(
                $emailDocument,
                estab_incident_pdf_text('Lage-Übung.png | image/png | 68 Byte')
            )
            && str_contains(
                $emailDocument,
                estab_incident_pdf_text(
                    'Notiz-Übung.txt | text/plain | 18 Byte'
                )
            ),
        'EML attachment lacks passive headers, body, or enclosed-file list'
    );
    $emailStreams = incident_pdf_security_embedded_streams($emailDocument);
    $assert(
        $emailStreams === [$emailPayload]
            && str_contains($emailDocument, md5($emailPayload)),
        'EML original is not a byte-identical verified EmbeddedFile'
    );
    $emailVisibleObjects = str_replace($emailPayload, '', $emailDocument);
    $assert(
        !str_contains($emailVisibleObjects, '<script')
            && !str_contains($emailVisibleObjects, '<iframe')
            && !str_contains($emailVisibleObjects, 'window.__estabEmailXss')
            && !str_contains($emailVisibleObjects, 'evil.invalid')
            && str_contains(
                $emailVisibleObjects,
                'Mail-HTML wurde niemals aktiv ausgef'
            ),
        'active mail HTML leaked outside the inert EmbeddedFile stream'
    );

    $binaryAttachmentPath = $temporaryRoot . '/archive.zip';
    $binaryPayload = base64_decode(
        'UEsFBgAAAAAAAAAAAAAAAAAAAAAAAA==',
        true
    );
    if (
        !is_string($binaryPayload)
        || file_put_contents($binaryAttachmentPath, $binaryPayload)
            !== strlen($binaryPayload)
    ) {
        throw new RuntimeException('Could not create binary PDF fixture');
    }
    $binaryPdf = new EstabIncidentPdf($incident, 1024);
    $binaryPdf->SetCompression(false);
    $binaryEmbedded = $binaryPdf->embedAttachment(
        $binaryAttachmentPath,
        'Anlage-Archiv.zip',
        'application/zip',
        'Nicht statisch darstellbares Testformat'
    );
    $binaryIndex = [[
        'display_name' => 'Anlage-Archiv.zip',
        'stored_name' => $binaryEmbedded['name'],
        'archive_name' => 'EL0002.zip',
        'size' => $binaryEmbedded['size'],
        'sha256' => $binaryEmbedded['sha256'],
        'mime' => $binaryEmbedded['mime'],
    ]];
    $binaryVisibility = $binaryPdf->addAttachmentPages($binaryIndex);
    $binaryDocument = $binaryPdf->Output('', 'S');
    $assert(
        $binaryVisibility['attachment_information_pages'] === 1
            && $binaryVisibility['attachment_rendered_pages'] === 0
            && str_contains($binaryDocument, 'Hinweisseite')
            && str_contains($binaryDocument, 'bytegleich in diesem Dossier'),
        'unsupported binary attachment lacks its honest visible information page'
    );

    $snapshotMimePdf = new EstabIncidentPdf($incident, 1024);
    $assertThrows(
        static fn (): array => $snapshotMimePdf->embedAttachment(
            $attachmentPath,
            'Anlage-Falschdeklaration.zip',
            'application/zip',
            'Snapshot MIME proof'
        ),
        'declared MIME differing from the immutable byte snapshot was accepted'
    );

    $mismatchPdf = new EstabIncidentPdf($incident, 1024);
    $mismatchEmbedded = $mismatchPdf->embedAttachment(
        $attachmentPath,
        'Anlage-Falsch.jpg',
        'text/plain',
        'MIME mismatch proof'
    );
    $assertThrows(
        static fn (): array => $mismatchPdf->addAttachmentPages([[
            'display_name' => 'Anlage-Falsch.jpg',
            'stored_name' => $mismatchEmbedded['name'],
            'archive_name' => 'EL0003.jpg',
            'size' => $mismatchEmbedded['size'],
            'sha256' => $mismatchEmbedded['sha256'],
            'mime' => $mismatchEmbedded['mime'],
        ]]),
        'renderable extension with mismatching detected MIME type was accepted'
    );
    $emailMismatchPdf = new EstabIncidentPdf($incident, 1024);
    $emailMismatchEmbedded = $emailMismatchPdf->embedAttachment(
        $attachmentPath,
        'Anlage-Falsch.eml',
        'text/plain',
        'EML mismatch proof'
    );
    $assertThrows(
        static fn (): array => $emailMismatchPdf->addAttachmentPages([[
            'display_name' => 'Anlage-Falsch.eml',
            'stored_name' => $emailMismatchEmbedded['name'],
            'archive_name' => 'EL0004.eml',
            'size' => $emailMismatchEmbedded['size'],
            'sha256' => $emailMismatchEmbedded['sha256'],
            'mime' => $emailMismatchEmbedded['mime'],
        ]]),
        'EML extension with mismatching detected MIME type was accepted'
    );

    $lineLimitPath = $temporaryRoot . '/too-many-lines.txt';
    $lineLimitPayload = str_repeat("x\n", 13000);
    if (
        file_put_contents($lineLimitPath, $lineLimitPayload)
            !== strlen($lineLimitPayload)
    ) {
        throw new RuntimeException('Could not create text page-limit fixture');
    }
    $lineLimitPdf = new EstabIncidentPdf($incident, 1024 * 1024);
    $lineLimitEmbedded = $lineLimitPdf->embedAttachment(
        $lineLimitPath,
        'Anlage-Zeilenlimit.txt',
        'text/plain',
        'Text page-limit proof'
    );
    $lineLimitStartedAt = microtime(true);
    $assertThrows(
        static fn (): array => $lineLimitPdf->addAttachmentPages([[
            'display_name' => 'Anlage-Zeilenlimit.txt',
            'stored_name' => $lineLimitEmbedded['name'],
            'archive_name' => 'EL0004.txt',
            'size' => $lineLimitEmbedded['size'],
            'sha256' => $lineLimitEmbedded['sha256'],
            'mime' => $lineLimitEmbedded['mime'],
        ]]),
        'plain-text attachment exceeded 200 pages before being rejected'
    );
    $assert(
        $lineLimitPdf->PageNo() === 1
            && microtime(true) - $lineLimitStartedAt < 2.0,
        'plain-text page limit was not enforced before bulk page allocation'
    );

    $crossShiftPdf = new EstabIncidentPdf($incident, 1024);
    $crossShiftPdf->SetCompression(false);
    $crossShiftPdf->addLogbook('ETB', [[
        'estab_book_lfd' => 22,
        'etb_lfd-nr' => 987654,
        'estab_event_time' => '2026-07-30 01:00:00.000000',
        'estab_recorded_at' => '2026-07-30 01:00:01.000000',
        'estab_event_type' => 'korrektur',
        'estab_correction_of' => 876543,
        'estab_correction_book_lfd' => 7,
        'estab_reference' => '7',
        'etb_aktion' => 'Schichtübergreifende ETB-Korrektur',
        'etb_bemerk' => 'Original liegt in einer anderen Dienstschicht',
        'etb_benutzer' => 'eStab-System',
        'etb_kuerzel' => 'system',
        'etb_funktion' => 'System',
    ]]);
    $crossShiftPdf->addLogbook('TBB', [[
        'estab_book_lfd' => 23,
        'tbb_lfd-nr' => 987655,
        'estab_event_time' => '2026-07-30 01:01:00.000000',
        'estab_recorded_at' => '2026-07-30 01:01:01.000000',
        'estab_entry_type' => 'korrektur',
        'estab_correction_of' => 876544,
        'estab_correction_book_lfd' => 8,
        'estab_operations' => 'Schichtübergreifende TBB-Korrektur',
        'tbb_bemerk' => 'Original liegt in einer anderen Dienstschicht',
        'tbb_benutzer' => 'eStab-System',
        'tbb_kuerzel' => 'system',
        'tbb_funktion' => 'System',
    ]]);
    $crossShiftDocument = $crossShiftPdf->Output('', 'S');
    $assert(
        str_contains($crossShiftDocument, 'Korrektur zu ETB-Nr.: 7')
            && str_contains($crossShiftDocument, 'Korrektur zu TBB-Nr.: 8')
            && substr_count(
                $crossShiftDocument,
                'Korrektur zu ETB-Nr.: 7'
            ) === 1
            && !str_contains($crossShiftDocument, 'Referenz: 7')
            && !str_contains($crossShiftDocument, '876543')
            && !str_contains($crossShiftDocument, '876544')
            && !str_contains($crossShiftDocument, '987654')
            && !str_contains($crossShiftDocument, '987655'),
        'Cross-shift correction exposes a global primary key as book number'
    );

    $unresolvedPdf = new EstabIncidentPdf($incident, 1024);
    $unresolvedPdf->SetCompression(false);
    $unresolvedPdf->addLogbook('ETB', [[
        'etb_lfd-nr' => 777777,
        'estab_event_time' => '2026-07-30 01:02:00.000000',
        'estab_recorded_at' => '2026-07-30 01:02:01.000000',
        'estab_event_type' => 'korrektur',
        'estab_correction_of' => 888888,
        'etb_aktion' => 'Nicht auflösbarer Testverweis',
        'etb_bemerk' => '',
    ]]);
    $unresolvedPdf->addLogbook('TBB', [[
        'tbb_lfd-nr' => 777778,
        'estab_event_time' => '2026-07-30 01:03:00.000000',
        'estab_recorded_at' => '2026-07-30 01:03:01.000000',
        'estab_entry_type' => 'korrektur',
        'estab_correction_of' => 888889,
        'estab_operations' => 'Nicht auflösbarer Testverweis',
        'tbb_bemerk' => '',
    ]]);
    $unresolvedDocument = $unresolvedPdf->Output('', 'S');
    $assert(
        substr_count(
            $unresolvedDocument,
            'Korrekturverweis vorhanden'
        ) === 2
            && !str_contains($unresolvedDocument, '777777')
            && !str_contains($unresolvedDocument, '777778')
            && !str_contains($unresolvedDocument, '888888')
            && !str_contains($unresolvedDocument, '888889'),
        'Unresolved correction renders a global primary key as book number'
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
    $missingVisiblePdf = new EstabIncidentPdf($incident, 1024);
    $assertThrows(
        static fn (): array => $missingVisiblePdf->addAttachmentPages([[
            'display_name' => 'Nicht eingebettet',
            'stored_name' => 'Anlage-fehlt.txt',
            'archive_name' => 'EL9999.txt',
            'size' => 1,
            'sha256' => str_repeat('0', 64),
            'mime' => 'text/plain',
        ]]),
        'visible attachment without a verified embedded snapshot was accepted'
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
