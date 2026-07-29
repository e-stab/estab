<?php

$repositoryRoot = dirname(__DIR__, 2);
require_once $repositoryRoot . '/app/bootstrap.php';

$_SERVER['DOCUMENT_ROOT'] = $repositoryRoot;
$originalDirectory = getcwd();
if (!chdir($repositoryRoot . '/4fbak')) {
    throw new RuntimeException('Could not enter the PDF module directory');
}

require_once $repositoryRoot . '/4fcfg/config.inc.php';
require_once $repositoryRoot . '/4fbak/backup_pdf.php';

$fields = [
    '00_lfd', 'einsatz_id', '01_datum', '01_medium', '01_zeichen', '02_zeichen',
    '02_zeit', '03_datum', '03_zeichen', '04_nummer', '04_richtung',
    '05_gegenstelle', '06_befweg', '06_befwegausw', '07_durchspruch',
    '08_befhinwausw', '08_befhinweis', '09_vorrangstufe',
    '10_anschrift', '11_gesprnotiz', '12_abfzeit', '12_anhang',
    '12_inhalt', '13_abseinheit', '14_funktion', '14_zeichen',
    '15_quitdatum', '15_quitzeichen', '16_empf', '17_vermerke',
    '99_lstacc', 'x00_status', 'x01_abschluss', 'x04_druck',
    'x05_druck_d',
];
$fixture = array_fill_keys($fields, '');
$fixture = array_replace($fixture, [
    '00_lfd' => 1,
    'einsatz_id' => 1,
    '01_medium' => 'Funk',
    '04_nummer' => 7,
    '04_richtung' => 'A',
    '07_durchspruch' => 'D',
    '10_anschrift' => 'Integrationsempfaenger',
    '12_abfzeit' => '2026-07-23 08:15:00',
    '12_inhalt' => 'eStab PDF Funktionsnachweis',
    '13_abseinheit' => 'Einsatzleitung',
    '14_funktion' => 'S1',
    '14_zeichen' => 'e2e001',
    '16_empf' => 'S2_rt,',
    'x00_status' => 8,
    'x01_abschluss' => 't',
    'x04_druck' => 'f',
]);

$functions = [
    'LS', 'S1', 'S2', 'S3',
    'S4', 'S5', 'S6', 'POL',
    'THW', 'SAN', '', '',
    '', '', '', '',
    '', '', '', '',
];
$recipientMatrix = [];
$functionIndex = 0;
for ($row = 1; $row <= 5; $row++) {
    for ($column = 1; $column <= 4; $column++) {
        $recipientMatrix[$row][$column] = ['fkt' => $functions[$functionIndex++]];
    }
}

$pdf = new vordruckaspdf($fixture, $recipientMatrix);
$pdf->SetTitle('eStab PDF smoke test');
$pdf->SetFont('helvetica');
$pdf->SetAutoPageBreak(true, $pdf->bottom - $pdf->point[38][1]);
$pdf->AddPage();
$pdf->writedata_inhalt();
$document = $pdf->Output('', 'S');

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

echo 'PDF smoke test: OK (' . strlen($document) . " bytes)\n";
