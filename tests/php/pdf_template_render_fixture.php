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
$single->AliasNbPages();
$single->SetFont('helvetica', '', 12);
$single->AddPage();
$single->writedata_inhalt();
$singleBytes = $single->Output('', 'S');

$incident = [
    'einsatz_id' => 1,
    'kennung' => 'RENDER-001',
    'name' => 'PDF-Vorlagenvergleich',
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
    'Render-Nachweis des eingebetteten Originalanhangs'
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
$longSingle->AliasNbPages();
$longSingle->SetFont('helvetica', '', 12);
$longSingle->AddPage();
$longSingle->writedata_inhalt();
$longSingleBytes = $longSingle->Output('', 'S');

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
    'Render-Nachweis des eingebetteten Originalanhangs'
);
$sections = ['etb', 'ttb', 'messages', 'attachments'];
$completeDossier->addCover(
    $incident,
    $sections,
    ['etb' => 1, 'ttb' => 1, 'messages' => 1, 'attachments' => 1],
    '29.07.2026 08:30:00 CEST',
    'PDF-Rendernachweis'
);
$completeDossier->addLogbook('ETB', [[
    'etb_lfd-nr' => 1,
    'etb_time' => '2026-07-29 08:00:00',
    'etb_aktion' => 'Produktionsnaher Layoutwechsel ETB',
    'etb_bemerk' => 'Vor der Nachrichtenvorlage',
    'etb_benutzer' => 'Rendernachweis',
    'etb_kuerzel' => 'REN001',
    'etb_funktion' => 'S2',
]]);
$completeDossier->addLogbook('TTB', [[
    'tbb_lfd-nr' => 1,
    'tbb_time' => '2026-07-29 08:05:00',
    'tbb_aktion' => 'Produktionsnaher Layoutwechsel TTB',
    'tbb_bemerk' => 'Unmittelbar vor der Nachrichtenvorlage',
    'tbb_benutzer' => 'Rendernachweis',
    'tbb_kuerzel' => 'REN002',
    'tbb_funktion' => 'A/W',
]]);
$completeDossier->addMessages(
    [$message],
    [1 => ['Render-Anlage.txt']]
);
$completeDossier->addAttachmentIndex([[
    'display_name' => 'Render-Anlage.txt',
    'stored_name' => $completeAttachment['name'],
    'size' => $completeAttachment['size'],
    'sha256' => $completeAttachment['sha256'],
    'mime' => $completeAttachment['mime'],
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
