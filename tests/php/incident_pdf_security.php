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
file_put_contents($attachmentPath, "Originalanlage\n");
chmod($attachmentPath, 0600);

try {
    $incident = [
        'kennung' => 'EL-2026-001',
        'name' => 'Sturm Münster',
        'beginn' => '2026-07-29 10:30:00',
        'ende' => null,
        'ort' => 'Münster',
        'organisation' => 'Kreis',
        'einsatzleitung' => 'Max Beispiel',
        'beschreibung' => 'Vollständiger Funktionsnachweis',
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
        'Originalanlage zu Nachricht E 1'
    );
    $pdf->addCover(
        $incident,
        ['etb', 'ttb', 'messages', 'attachments'],
        ['etb' => 1, 'ttb' => 1, 'messages' => 1, 'attachments' => 1],
        '29.07.2026 12:00:00',
        'estab-admin'
    );
    $pdf->addLogbook('ETB', [[
        'etb_lfd-nr' => 1,
        'etb_time' => '2026-07-29 10:31:00',
        'etb_aktion' => 'Einsatz eröffnet',
        'etb_bemerk' => 'Führungsstelle besetzt',
        'etb_benutzer' => 'Ada Beispiel',
        'etb_kuerzel' => 'ADA001',
        'etb_funktion' => 'S2',
    ]]);
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
    $pdf->addAttachmentIndex([[
        'display_name' => 'EL0001.txt · lage.txt',
        'stored_name' => $embedded['name'],
        'size' => $embedded['size'],
        'sha256' => $embedded['sha256'],
        'mime' => $embedded['mime'],
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
        'TTB columns were not mapped into the PDF'
    );
    $assert(str_ends_with($document, "%%EOF\n"), 'PDF trailer missing');
    $assert(strlen($document) > 5000, 'incident PDF is unexpectedly small');
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
