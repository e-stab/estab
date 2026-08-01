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
foreach (
    [
        'EINGANG',
        'AUSGANG',
        'Nachweis-Nr.',
        'Fm-Betriebsstelle',
        'Blitz',
        'Ruf Nr.',
        '0711 123456',
        'Lagebetreff Nord',
    ]
    as $marker
) {
    if (!str_contains($document, $marker)) {
        throw new RuntimeException('Message form marker is missing: ' . $marker);
    }
}
if (str_contains($document, 'bbb')) {
    throw new RuntimeException('Message form exposes a raw priority code');
}
if (
    !str_contains($document, 'ALT_1 [gn]')
    || !str_contains($document, 'ALT2 [rt]')
    || !str_contains($document, 'Fernmelder [gn]')
    || str_contains($document, 'A/W')
) {
    throw new RuntimeException(
        'Recipient outside the current matrix is not visible'
    );
}
if (str_contains($document, '/Subtype /Image')) {
    throw new RuntimeException('Message form contains an unexpected image');
}

// The Nachrichtenform/Vorrang band has exactly the official 61/39 divider.
// Keep this vector-level proof in addition to the rendered pixel comparison:
// it catches a visually easy-to-miss legacy divider inside Nachrichtenform.
if (!str_contains($document, '357.17 683.15 m 357.17 646.30 l S')) {
    throw new RuntimeException('Official 61/39 priority divider is missing');
}
if (str_contains($document, '184.25 683.15 m 184.25 646.30 l S')) {
    throw new RuntimeException('Legacy divider still splits Nachrichtenform');
}

// Prove that the selected Staatsnot X is fully contained in its square. The
// line half-width is included so a visually protruding stroke fails as well.
$checkboxMatched = preg_match(
    '/\(Staatsnot\) Tj ET Q\s+'
        . '(?:[0-9.]+ w\s+[0-9.]+ G\s+)'
        . '([0-9.]+) ([0-9.]+) ([0-9.]+) -([0-9.]+) re S\s+'
        . '([0-9.]+) w\s+[0-9.]+ [0-9.]+ [0-9.]+ RG\s+'
        . '([0-9.]+) ([0-9.]+) m ([0-9.]+) ([0-9.]+) l S\s+'
        . '([0-9.]+) w\s+[0-9.]+ [0-9.]+ [0-9.]+ RG\s+'
        . '([0-9.]+) ([0-9.]+) m ([0-9.]+) ([0-9.]+) l S/',
    $document,
    $checkbox
);
if ($checkboxMatched !== 1) {
    throw new RuntimeException('Selected Staatsnot checkbox geometry missing');
}
$left = (float) $checkbox[1];
$top = (float) $checkbox[2];
$right = $left + (float) $checkbox[3];
$bottom = $top - (float) $checkbox[4];
$halfStroke = max((float) $checkbox[5], (float) $checkbox[10]) / 2;
$coordinates = [
    [(float) $checkbox[6], (float) $checkbox[7]],
    [(float) $checkbox[8], (float) $checkbox[9]],
    [(float) $checkbox[11], (float) $checkbox[12]],
    [(float) $checkbox[13], (float) $checkbox[14]],
];
foreach ($coordinates as [$x, $y]) {
    if (
        $x - $halfStroke < $left
        || $x + $halfStroke > $right
        || $y - $halfStroke < $bottom
        || $y + $halfStroke > $top
    ) {
        throw new RuntimeException('Staatsnot X protrudes from its checkbox');
    }
}

echo 'PDF smoke test: OK (' . strlen($document) . " bytes)\n";
