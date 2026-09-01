<?php

$repositoryRoot = dirname(__DIR__, 2);
require_once $repositoryRoot . '/app/bootstrap.php';
require_once __DIR__ . '/pdf_test_fixture.php';
require_once $repositoryRoot . '/app/nv_raster.php';
require_once $repositoryRoot . '/app/nv_field_numbers.php';

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
$pdf->SetAutoPageBreak(true, $pdf->message_form_break_margin());
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
$raster = estab_nv_raster();

// Der Abzug traegt dasselbe Raster wie die Bildschirmansicht: die drei Zonen,
// die Bearbeitungsvermerke und die Bloecke des Verteilers.
foreach (
    [
        'Fm-Zentrale',
        'Sichter',
        'Aufnahmevermerk',
        'Annahmevermerk',
        'Technisches',
        'Betriebsbuch',
        'Rufname der Gegenstelle',
        'Abfassungszeit:',
        'Quittung:',
        'Vermerke:',
        'TEL/EL/EAL/UEAL',
        'Verb.stellen',
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

// Das Raster des Altbestandes ist fort. Wer es wieder einbaut, druckt einen
// anderen Vordruck als die Oberflaeche.
foreach (['Fm-Betriebsstelle', 'Nachweis-Nr.'] as $legacy) {
    if (str_contains($document, $legacy)) {
        throw new RuntimeException('Legacy message form raster is back: ' . $legacy);
    }
}

// Die Feldnummern der Ausfuellanleitung stehen auf dem Blatt. Wer "13" liest,
// schlaegt Anweisung 13 nach.
foreach (array_keys(estab_nv_field_map()) as $printedNumber) {
    if (!str_contains($document, '(' . $printedNumber . ') Tj')) {
        throw new RuntimeException(
            'Printed field number is missing: ' . $printedNumber
        );
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

// Der Ausdruck darf kein Ankreuzfeld vortaeuschen, das der Vordruck nicht
// hat (SPEC, NV-09-VORRANGSTUFE). Staatsnot wird benannt, nicht gekreuzt.
if (!str_contains($document, 'Vorrangstufe: Staatsnot')) {
    throw new RuntimeException('Priority beyond Blitz is not named');
}
if (str_contains($document, '(Staatsnot) Tj')) {
    throw new RuntimeException('Staatsnot is printed as a checkbox label');
}

// Nachrichtenform und Vorrang teilen sich eine Zeile. Die Trennung steht
// dort, wo das Raster sie fuehrt -- gerechnet, nicht abgeschrieben, damit
// eine Rastermessung den Beweis nicht stillschweigend ueberholt.
$sheetLeft = (210.0 - (float) $raster['breite']) / 2.0;
$sheetTop = 9.0;
$scale = 72.0 / 25.4;
$dividerX = ($sheetLeft + (float) $raster['x']['zeichen']) * $scale;
$divider = sprintf(
    '%.2F %.2F m %.2F %.2F l S',
    $dividerX,
    (297.0 - ($sheetTop + (float) $raster['y']['weg_ende'])) * $scale,
    $dividerX,
    (297.0 - ($sheetTop + (float) $raster['y']['art_ende'])) * $scale
);
if (!str_contains($document, $divider)) {
    throw new RuntimeException('Official priority divider is missing');
}

// Jede gesetzte Marke bleibt in ihrem Kaestchen. FPDF zeichnet das Kaestchen
// als "re B" und die Marke unmittelbar danach als "re f"; genau diese Paare
// werden geprueft.
$rectangles = [];
preg_match_all(
    '/([0-9.]+) ([0-9.]+) ([0-9.]+) -([0-9.]+) re (B|f)\b/',
    $document,
    $matches,
    PREG_SET_ORDER
);
foreach ($matches as $match) {
    $rectangles[] = [
        'left' => (float) $match[1],
        'top' => (float) $match[2],
        'width' => (float) $match[3],
        'height' => (float) $match[4],
        'operator' => $match[5],
    ];
}
$markedBoxes = 0;
foreach ($rectangles as $index => $rectangle) {
    if ($rectangle['operator'] !== 'f' || $index === 0) {
        continue;
    }
    $box = $rectangles[$index - 1];
    if ($box['operator'] !== 'B') {
        continue;
    }
    $markedBoxes++;
    if (
        $rectangle['left'] < $box['left']
        || $rectangle['top'] > $box['top']
        || $rectangle['left'] + $rectangle['width']
            > $box['left'] + $box['width']
        || $rectangle['top'] - $rectangle['height']
            < $box['top'] - $box['height']
    ) {
        throw new RuntimeException('A checkbox mark protrudes from its box');
    }
}
if ($markedBoxes < 1) {
    throw new RuntimeException('No checkbox of the fixture is marked');
}

// Feld 19 verteilt die Matrix auf die drei Bloecke des Vordrucks. Die
// Fachberater stehen in ihrem eigenen, nicht in der Fuehrungsspalte.
foreach (['POL', 'THW', 'SAN', 'Leiter', 'S6'] as $recipient) {
    if (!str_contains($document, '(' . $recipient . ') Tj')) {
        throw new RuntimeException(
            'Distribution block entry is missing: ' . $recipient
        );
    }
}

echo 'PDF smoke test: OK (' . strlen($document) . " bytes)\n";
