<?php

declare(strict_types=1);

require_once __DIR__ . '/../../4fach/upload_class.php';
require_once __DIR__ . '/../../app/attachment.php';

$assertions = 0;

function upload_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "upload security: FAIL: {$message}\n");
        exit(1);
    }
}

$upload = new file_upload();
$upload->extensions = ['.pdf', '.txt'];
$upload->rename_file = true;
$upload->the_file = 'lage.pdf';

upload_assert($upload->set_file_name('EL000001') === 'EL000001.pdf', 'safe generated name accepted');
upload_assert($upload->set_file_name('../outside') === false, 'path traversal in generated name rejected');
upload_assert($upload->set_file_name('EL/000001') === false, 'path separator in generated name rejected');
upload_assert($upload->check_file_name('../lage.pdf') === false, 'path traversal in original name rejected');
upload_assert($upload->check_file_name('lage.php.pdf') === true, 'benign multi-dot name remains supported');
upload_assert($upload->get_extension('LAGE.PDF') === '.pdf', 'extension is normalized');

$realPdf = tempnam(sys_get_temp_dir(), 'estab-pdf-');
$fakePdf = tempnam(sys_get_temp_dir(), 'estab-fake-');
if ($realPdf === false || $fakePdf === false) {
    fwrite(STDERR, "upload security: FAIL: unable to create fixtures\n");
    exit(1);
}

try {
    file_put_contents($realPdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    file_put_contents($fakePdf, "This is plain text, not a PDF.\n");

    $upload->the_temp_file = $realPdf;
    upload_assert($upload->validateExtension() === true, 'matching PDF content accepted');

    $upload->the_temp_file = $fakePdf;
    upload_assert($upload->validateExtension() === false, 'fake PDF content rejected');
} finally {
    @unlink($realPdf);
    @unlink($fakePdf);
}

$realJpeg = __DIR__ . '/../../4fach/design/HS/null.jpg';
$jpegUpload = new file_upload();
$jpegUpload->extensions = array_map(
    static fn (string $extension): string => '.' . $extension,
    estab_attachment_allowed_extensions()
);
$jpegUpload->the_temp_file = $realJpeg;
foreach (['lage.jpg', 'lage.jpeg', 'LAGE.JPG', 'LAGE.JPEG'] as $jpegName) {
    $jpegUpload->the_file = $jpegName;
    upload_assert(
        $jpegUpload->validateExtension() === true,
        $jpegName . ' with real image/jpeg content accepted'
    );
}
$jpegUpload->the_temp_file = __FILE__;
$jpegUpload->the_file = 'fake.JPEG';
upload_assert(
    $jpegUpload->validateExtension() === false
        && $jpegUpload->failure_code === 18
        && $jpegUpload->user_error_message()
            === 'Dateiendung und erkannter Dateityp passen nicht zusammen.',
    'plain text renamed to JPEG is rejected with a safe specific reason'
);
$jpegUpload->the_temp_file = $realJpeg;
$jpegUpload->the_file = 'lage.heic';
upload_assert(
    $jpegUpload->validateExtension() === false
        && $jpegUpload->failure_code === 11
        && $jpegUpload->user_error_message()
            === 'Diese Dateiendung wird nicht unterstützt.',
    'unsupported image extension is rejected with a safe specific reason'
);

$realEmail = tempnam(sys_get_temp_dir(), 'estab-eml-');
$fakeEmail = tempnam(sys_get_temp_dir(), 'estab-fake-eml-');
$brokenEmail = tempnam(sys_get_temp_dir(), 'estab-broken-eml-');
if ($realEmail === false || $fakeEmail === false || $brokenEmail === false) {
    fwrite(STDERR, "upload security: FAIL: unable to create email fixtures\n");
    exit(1);
}
try {
    file_put_contents(
        $realEmail,
        "From: einsatz@example.invalid\r\n"
            . "To: fuest@example.invalid\r\n"
            . "Subject: Lageuebergabe\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . "Sicher darstellbarer Nachrichtentext.\r\n"
    );
    file_put_contents($fakeEmail, "Nur Text, aber keine RFC-822-E-Mail.\n");
    file_put_contents(
        $brokenEmail,
        "From: einsatz@example.invalid\r\n"
            . "Subject: Defekte MIME-Struktur\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed\r\n\r\n"
            . "Grenze fehlt.\r\n"
    );
    $emailUpload = new file_upload();
    $emailUpload->extensions = array_map(
        static fn (string $extension): string => '.' . $extension,
        estab_attachment_allowed_extensions()
    );
    $emailUpload->the_temp_file = $realEmail;
    $emailUpload->the_file = 'Lageuebergabe.EML';
    upload_assert(
        $emailUpload->validateExtension() === true,
        'a structurally valid message/rfc822 EML file is rejected'
    );
    $emailUpload->the_temp_file = $fakeEmail;
    $emailUpload->the_file = 'Nur-Text.eml';
    upload_assert(
        $emailUpload->validateExtension() === false
            && $emailUpload->failure_code === 18,
        'plain text renamed to EML bypasses MIME and RFC-822 validation'
    );
    $emailUpload->the_temp_file = $brokenEmail;
    $emailUpload->the_file = 'Defekte-Struktur.eml';
    upload_assert(
        $emailUpload->validateExtension() === false
            && $emailUpload->failure_code === 20
            && str_contains(
                $emailUpload->user_error_message(),
                'E-Mail-Struktur konnte nicht sicher gelesen werden'
            ),
        'malformed message/rfc822 data lacks a specific safe upload error'
    );
} finally {
    @unlink($realEmail);
    @unlink($fakeEmail);
    @unlink($brokenEmail);
}

putenv('ESTAB_UPLOAD_MAX_BYTES=1234');
$configured = new file_upload();
upload_assert($configured->max_file_size === 1234, 'deployment upload limit applied');
upload_assert($configured->upload_limit_label() === '1,2 KiB', 'small deployment limit is formatted safely');
putenv('ESTAB_UPLOAD_MAX_BYTES=20971520');
$configured = new file_upload();
upload_assert($configured->upload_limit_label() === '20 MiB', 'whole MiB limit is formatted without decimals');
putenv('ESTAB_UPLOAD_MAX_BYTES');

upload_assert($upload->del_temp_file('/definitely/not/a/file') === true, 'missing temp file is harmless');

printf("upload security: OK (%d assertions)\n", $assertions);
