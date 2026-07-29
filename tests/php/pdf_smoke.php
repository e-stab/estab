<?php

$repositoryRoot = dirname(__DIR__, 2);
require_once $repositoryRoot . '/app/bootstrap.php';
require_once __DIR__ . '/pdf_test_fixture.php';

$_SERVER['DOCUMENT_ROOT'] = $repositoryRoot;
$originalDirectory = getcwd();
if (!chdir($repositoryRoot . '/4fbak')) {
    throw new RuntimeException('Could not enter the PDF module directory');
}

require_once $repositoryRoot . '/4fcfg/config.inc.php';
require_once $repositoryRoot . '/4fbak/backup_pdf.php';

$fixture = estab_pdf_test_message_fixture();
$recipientMatrix = estab_pdf_test_recipient_matrix();
$pdf = new vordruckaspdf($fixture, $recipientMatrix);
$pdf->SetCompression(false);
$pdf->SetTitle('eStab PDF smoke test');
$pdf->SetFont('helvetica');
$pdf->SetAutoPageBreak(true, $pdf->bottom - $pdf->point[38][1]);
$pdf->AddPage();
$pdf->writedata_inhalt();
$document = $pdf->Output('', 'S');
$fixtureOutput = getenv('ESTAB_MESSAGE_PDF_FIXTURE_OUTPUT');
if (is_string($fixtureOutput) && $fixtureOutput !== '') {
    if (
        !str_starts_with($fixtureOutput, '/')
        || !is_dir(dirname($fixtureOutput))
        || file_put_contents($fixtureOutput, $document) !== strlen($document)
    ) {
        throw new RuntimeException('Could not write requested PDF fixture');
    }
}

if ($originalDirectory !== false) {
    chdir($originalDirectory);
}

if (!str_starts_with($document, '%PDF-')) {
    throw new RuntimeException('Generated document has no PDF header');
}
if (!str_ends_with($document, "%%EOF\n")) {
    throw new RuntimeException('Generated document has no PDF trailer');
}
if (strlen($document) < 5000) {
    throw new RuntimeException('Generated PDF is unexpectedly small');
}
foreach (['EINGANG', 'AUSGANG', 'Nachweis-Nr.', 'Fm-Betriebsstelle'] as $marker) {
    if (!str_contains($document, $marker)) {
        throw new RuntimeException('Message form marker is missing: ' . $marker);
    }
}
if (
    !str_contains($document, 'ALT_1 [gn]')
    || !str_contains($document, 'ALT2 [rt]')
) {
    throw new RuntimeException(
        'Recipient outside the current matrix is not visible'
    );
}
if (
    str_contains($document, 'Dienstgebrauch')
    || str_contains($document, 'VS-NfD')
) {
    throw new RuntimeException('Message form still contains a VS marking');
}
if (str_contains($document, '/Subtype /Image')) {
    throw new RuntimeException('Message form still contains the coat of arms');
}

echo 'PDF smoke test: OK (' . strlen($document) . " bytes)\n";
