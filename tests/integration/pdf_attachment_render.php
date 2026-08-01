<?php

declare(strict_types=1);

if (getenv('ESTAB_PDF_ATTACHMENT_RENDER_INTEGRATION') !== '1') {
    fwrite(STDERR, "ESTAB_PDF_ATTACHMENT_RENDER_INTEGRATION=1 is required\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/app/incident_pdf.php';

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

/** @return list<string> */
function pdf_attachment_render_embedded_streams(string $pdf): array
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
        $wholeMatch = (string) ($match[0][0] ?? '');
        $offset = (int) ($match[0][1] ?? -1) + strlen($wholeMatch);
        $stream = $length >= 0 && $offset >= 0
            ? substr($pdf, $offset, $length)
            : '';
        if (strlen($stream) !== $length) {
            throw new RuntimeException('Visible attachment EmbeddedFile is truncated');
        }
        $streams[] = $stream;
    }
    return $streams;
}

/** @return list<string> */
function pdf_attachment_render_workspaces(): array
{
    $workspaces = glob(
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . '/estab-pdf-render-*',
        GLOB_ONLYDIR
    );
    if (!is_array($workspaces)) {
        return [];
    }
    sort($workspaces, SORT_STRING);
    return $workspaces;
}

/** Build a valid two-page PDF with a visible text-annotation icon on page 1. */
function pdf_attachment_render_annotated_source(): string
{
    $pageOne = "q\n0.855 0.922 0.988 rg\n28 28 539 786 re f\nQ\n";
    $pageTwo = "q\n0.988 0.898 0.804 rg\n28 28 539 786 re f\nQ\n";
    $objects = [
        1 => '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 '
            . '/MediaBox [0 0 595 842] >>',
        2 => '<< >>',
        3 => '<< /Type /Page /Parent 1 0 R /Resources 2 0 R '
            . '/Contents 4 0 R /Annots [7 0 R] >>',
        4 => '<< /Length ' . strlen($pageOne) . ">>\nstream\n"
            . $pageOne . 'endstream',
        5 => '<< /Type /Page /Parent 1 0 R /Resources 2 0 R '
            . '/Contents 6 0 R >>',
        6 => '<< /Length ' . strlen($pageTwo) . ">>\nstream\n"
            . $pageTwo . 'endstream',
        7 => '<< /Type /Annot /Subtype /Text /Rect [60 730 84 754] '
            . '/Contents (ANNOTATION-SICHTBAR) /Name /Comment '
            . '/C [1 0.8 0] /Open false >>',
        8 => '<< /Type /Catalog /Pages 1 0 R >>',
    ];

    $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $number => $object) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 9\n0000000000 65535 f \n";
    for ($number = 1; $number <= 8; $number++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
    }
    return $pdf . "trailer\n<< /Size 9 /Root 8 0 R >>\nstartxref\n"
        . $xrefOffset . "\n%%EOF\n";
}

/** @return list<string> */
function pdf_attachment_render_jpeg_streams(string $pdf): array
{
    $matched = preg_match_all(
        '/\/Subtype \/Image\b.*?\/Filter \/DCTDecode.*?'
            . '\/Length ([0-9]+)\s*>>\s*stream\r?\n/s',
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
        $wholeMatch = (string) ($match[0][0] ?? '');
        $offset = (int) ($match[0][1] ?? -1) + strlen($wholeMatch);
        $stream = $length >= 0 && $offset >= 0
            ? substr($pdf, $offset, $length)
            : '';
        if (strlen($stream) !== $length) {
            throw new RuntimeException('Visible attachment JPEG is truncated');
        }
        $streams[] = $stream;
    }
    return $streams;
}

function pdf_attachment_render_has_yellow_annotation(string $jpeg): bool
{
    $image = @imagecreatefromstring($jpeg);
    if ($image === false) {
        throw new RuntimeException('Could not decode rendered PDF attachment page');
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $yellowPixels = 0;
    for ($y = (int) ($height * 0.07); $y < (int) ($height * 0.17); $y += 2) {
        for ($x = (int) ($width * 0.07); $x < (int) ($width * 0.18); $x += 2) {
            $color = imagecolorat($image, $x, $y);
            $red = ($color >> 16) & 0xff;
            $green = ($color >> 8) & 0xff;
            $blue = $color & 0xff;
            if ($red > 180 && $green > 120 && $blue < 120) {
                $yellowPixels++;
            }
        }
    }
    $image = null;
    return $yellowPixels >= 20;
}

$temporaryRoot = sys_get_temp_dir()
    . '/estab-visible-attachment-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryRoot, 0700)) {
    throw new RuntimeException('Could not create attachment render test root');
}
$sourcePdfPath = $temporaryRoot . '/source.pdf';
$sourcePngPath = $temporaryRoot . '/source.png';
$sourceGifPath = $temporaryRoot . '/source.gif';
$brokenPdfPath = $temporaryRoot . '/broken.pdf';

try {
    foreach ([
        ESTAB_INCIDENT_PDF_PRLIMIT,
        ESTAB_INCIDENT_PDF_PDFINFO,
        ESTAB_INCIDENT_PDF_PDFTOPPM,
    ] as $binary) {
        $assert(
            is_file($binary) && is_executable($binary),
            'Required attachment renderer binary is unavailable: ' . $binary
        );
    }

    $sourcePdfBytes = pdf_attachment_render_annotated_source();
    if (file_put_contents($sourcePdfPath, $sourcePdfBytes) !== strlen($sourcePdfBytes)) {
        throw new RuntimeException('Could not write source PDF attachment');
    }

    $png = imagecreatetruecolor(640, 360);
    if ($png === false) {
        throw new RuntimeException('Could not create PNG attachment');
    }
    imagealphablending($png, false);
    imagesavealpha($png, true);
    $transparent = imagecolorallocatealpha($png, 255, 255, 255, 127);
    imagefilledrectangle($png, 0, 0, 639, 359, $transparent);
    $blue = imagecolorallocatealpha($png, 32, 69, 138, 0);
    $yellow = imagecolorallocatealpha($png, 255, 204, 0, 30);
    imagefilledrectangle($png, 30, 30, 610, 160, $blue);
    imagefilledellipse($png, 320, 250, 300, 160, $yellow);
    if (!imagepng($png, $sourcePngPath, 6)) {
        throw new RuntimeException('Could not write PNG attachment');
    }
    $png = null;
    $sourcePngBytes = file_get_contents($sourcePngPath);
    if (!is_string($sourcePngBytes) || $sourcePngBytes === '') {
        throw new RuntimeException('Could not read PNG attachment');
    }

    $gif = imagecreatetruecolor(360, 240);
    if ($gif === false) {
        throw new RuntimeException('Could not create GIF attachment');
    }
    $gifWhite = imagecolorallocate($gif, 255, 255, 255);
    $gifBlue = imagecolorallocate($gif, 23, 47, 77);
    $gifYellow = imagecolorallocate($gif, 255, 204, 0);
    imagefilledrectangle($gif, 0, 0, 359, 239, $gifWhite);
    imagefilledrectangle($gif, 24, 24, 335, 105, $gifBlue);
    imagefilledellipse($gif, 180, 175, 190, 90, $gifYellow);
    if (!imagegif($gif, $sourceGifPath)) {
        throw new RuntimeException('Could not write GIF attachment');
    }
    $gif = null;
    $sourceGifBytes = file_get_contents($sourceGifPath);
    if (!is_string($sourceGifBytes) || $sourceGifBytes === '') {
        throw new RuntimeException('Could not read GIF attachment');
    }

    $incident = [
        'einsatz_id' => 77,
        'kennung' => 'PDF-ANLAGEN-TEST',
        'name' => 'Sichtbare Anlagen',
        'beginn' => '2026-08-01 00:00:00',
        'fuehrungsstellenname' => 'Führungsstelle Rendernachweis',
        'estab_status' => 'open',
    ];
    $dossier = new EstabIncidentPdf($incident, 4 * 1024 * 1024);
    $dossier->SetCompression(false);
    $pdfEmbedded = $dossier->embedAttachment(
        $sourcePdfPath,
        'Anlage-1-Mehrseitig.pdf',
        'application/pdf',
        'Mehrseitige PDF-Anlage',
        hash('sha256', $sourcePdfBytes),
        strlen($sourcePdfBytes)
    );
    $pngEmbedded = $dossier->embedAttachment(
        $sourcePngPath,
        'Anlage-2-Transparenz.png',
        'image/png',
        'PNG-Anlage mit Transparenz',
        hash('sha256', $sourcePngBytes),
        strlen($sourcePngBytes)
    );
    $gifEmbedded = $dossier->embedAttachment(
        $sourceGifPath,
        'Anlage-3-Grafik.gif',
        'image/gif',
        'GIF-Anlage',
        hash('sha256', $sourceGifBytes),
        strlen($sourceGifBytes)
    );
    $index = [[
        'display_name' => 'Mehrseitige PDF-Anlage',
        'stored_name' => $pdfEmbedded['name'],
        'archive_name' => 'EL0001.pdf',
        'size' => $pdfEmbedded['size'],
        'sha256' => $pdfEmbedded['sha256'],
        'mime' => $pdfEmbedded['mime'],
    ], [
        'display_name' => 'PNG-Anlage mit Transparenz',
        'stored_name' => $pngEmbedded['name'],
        'archive_name' => 'EL0002.png',
        'size' => $pngEmbedded['size'],
        'sha256' => $pngEmbedded['sha256'],
        'mime' => $pngEmbedded['mime'],
    ], [
        'display_name' => 'GIF-Anlage',
        'stored_name' => $gifEmbedded['name'],
        'archive_name' => 'EL0003.gif',
        'size' => $gifEmbedded['size'],
        'sha256' => $gifEmbedded['sha256'],
        'mime' => $gifEmbedded['mime'],
    ]];

    $workspacesBefore = pdf_attachment_render_workspaces();
    $dossier->addAttachmentIndex($index);
    $stats = $dossier->addAttachmentPages($index);
    $document = $dossier->Output('', 'S');
    $workspacesAfter = pdf_attachment_render_workspaces();
    $fixtureOutput = getenv('ESTAB_PDF_ATTACHMENT_RENDER_OUTPUT');
    if (is_string($fixtureOutput) && $fixtureOutput !== '') {
        if (
            !str_starts_with($fixtureOutput, '/')
            || !is_dir(dirname($fixtureOutput))
            || file_put_contents($fixtureOutput, $document)
                !== strlen($document)
        ) {
            throw new RuntimeException(
                'Could not write requested visible-attachment PDF fixture'
            );
        }
    }

    $assert(
        $stats === [
            'attachment_visible_count' => 3,
            'attachment_visible_pages' => 4,
            'attachment_rendered_count' => 3,
            'attachment_rendered_pages' => 4,
            'attachment_information_pages' => 0,
        ],
        'Visible PDF/PNG/GIF attachment counters are wrong'
    );
    $assert(
        str_starts_with($document, '%PDF-1.7')
            && str_ends_with($document, "%%EOF\n")
            && substr_count($document, 'Anlage 1 von 3') === 2
            && substr_count($document, 'Anlage 2 von 3') === 1
            && substr_count($document, 'Anlage 3 von 3') === 1
            && str_contains($document, 'Originalseite 1 von 2')
            && str_contains($document, 'Originalseite 2 von 2')
            && str_contains($document, 'Bild 1 von 1'),
        'Visible attachment page order or labels are incomplete'
    );
    preg_match_all(
        '/\/Subtype \/Image\s+\/Width ([0-9]+)\s+\/Height ([0-9]+)/',
        $document,
        $images,
        PREG_SET_ORDER
    );
    $assert(
        count($images) === 4,
        'PDF, PNG, and GIF pages did not become four image objects'
    );
    $jpegStreams = pdf_attachment_render_jpeg_streams($document);
    $assert(
        count($jpegStreams) === 4
            && pdf_attachment_render_has_yellow_annotation($jpegStreams[0]),
        'PDF text annotation was hidden instead of rendered visibly'
    );
    $embeddedStreams = pdf_attachment_render_embedded_streams($document);
    $assert(
        $embeddedStreams === [
            $sourcePdfBytes,
            $sourcePngBytes,
            $sourceGifBytes,
        ],
        'Visible attachment originals are not byte-identical EmbeddedFiles'
    );
    $assert(
        $workspacesAfter === $workspacesBefore
            && !str_contains($document, $temporaryRoot)
            && !str_contains($document, 'estab-pdf-render-'),
        'Renderer workspace was retained or leaked into the dossier'
    );

    $brokenBytes = "%PDF-1.7\ninvalid but integrity-bound\n%%EOF\n";
    if (file_put_contents($brokenPdfPath, $brokenBytes) !== strlen($brokenBytes)) {
        throw new RuntimeException('Could not write broken PDF fixture');
    }
    $broken = new EstabIncidentPdf($incident, 1024 * 1024);
    $brokenEmbedded = $broken->embedAttachment(
        $brokenPdfPath,
        'Anlage-Defekt.pdf',
        'application/pdf',
        'Defekter PDF-Negativtest',
        hash('sha256', $brokenBytes),
        strlen($brokenBytes)
    );
    $rejected = false;
    try {
        $broken->addAttachmentPages([[
            'display_name' => 'Defekte PDF-Anlage',
            'stored_name' => $brokenEmbedded['name'],
            'archive_name' => 'EL0004.pdf',
            'size' => $brokenEmbedded['size'],
            'sha256' => $brokenEmbedded['sha256'],
            'mime' => $brokenEmbedded['mime'],
        ]]);
    } catch (EstabIncidentPdfInputException) {
        $rejected = true;
    }
    $assert($rejected, 'Broken PDF attachment was silently omitted or accepted');
    $assert(
        pdf_attachment_render_workspaces() === $workspacesBefore,
        'Failed PDF rasterization retained a private workspace'
    );
} finally {
    foreach (
        [$sourcePdfPath, $sourcePngPath, $sourceGifPath, $brokenPdfPath]
        as $path
    ) {
        @unlink($path);
    }
    @rmdir($temporaryRoot);
}

echo "PDF attachment render integration: OK ({$assertions} assertions)\n";
