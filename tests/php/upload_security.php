<?php

declare(strict_types=1);

require_once __DIR__ . '/../../4fach/upload_class.php';

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

putenv('ESTAB_UPLOAD_MAX_BYTES=1234');
$configured = new file_upload();
upload_assert($configured->max_file_size === 1234, 'deployment upload limit applied');
putenv('ESTAB_UPLOAD_MAX_BYTES');

upload_assert($upload->del_temp_file('/definitely/not/a/file') === true, 'missing temp file is harmless');

printf("upload security: OK (%d assertions)\n", $assertions);
