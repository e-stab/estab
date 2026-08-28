<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/file_access.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertRejected = static function (callable $callback, string $message) use ($assert): void {
    $rejected = false;
    try {
        $callback();
    } catch (InvalidArgumentException | RuntimeException) {
        $rejected = true;
    }
    $assert($rejected, $message);
};

$anonymousLegacySession = [
    'vStab_benutzer' => '',
    'vStab_kuerzel' => '',
    'vStab_funktion' => '',
    'vStab_rolle' => '',
];
$assert(
    !estab_auth_session_is_authenticated($anonymousLegacySession),
    'empty keys initialised by mainindex must not authenticate a file request'
);
$identity = estab_auth_session_identity([
    'vStab_benutzer' => 'Ada Beispiel',
    'vStab_kuerzel' => 'ADA',
    'vStab_funktion' => 'A/W',
    'vStab_rolle' => 'Fernmelder',
]);
$assert(is_array($identity), 'complete login identity rejected');
$assert($identity['kuerzel'] === 'ada', 'session identity code not normalised');
$assert(
    !estab_auth_session_is_authenticated([
        'vStab_benutzer' => 'Ada',
        'vStab_kuerzel' => 'ada',
        'vStab_funktion' => 'S2',
    ]),
    'incomplete session authenticated'
);
$assert(
    !estab_auth_session_is_authenticated([
        'vStab_benutzer' => '<script>',
        'vStab_kuerzel' => 'ada',
        'vStab_funktion' => 'S2',
        'vStab_rolle' => 'Stab',
    ]),
    'unsafe session identity authenticated'
);

$assert(
    estab_file_validate_name('attachment', 'EL0001.pdf') === 'EL0001.pdf',
    'stored attachment name rejected'
);
$assert(
    estab_file_validate_name('attachment', 'EL0002.PDF') === 'EL0002.pdf',
    'attachment extension is not canonical on a case-sensitive filesystem'
);
$assert(
    estab_file_validate_name('attachment', 'EL0003.EML') === 'EL0003.eml',
    'email attachment extension is not accepted and canonicalised'
);
$assert(
    estab_file_validate_name('vordruck', 'estab 42 A.pdf') === 'estab 42 A.pdf',
    'generated form name with legacy spaces rejected'
);
foreach ([
    ['attachment', '../EL0001.pdf'],
    ['attachment', 'folder/EL0001.pdf'],
    ['attachment', "EL0001.pdf\0.php"],
    ['attachment', 'EL0001.php'],
    ['vordruck', '../estab 42 A.pdf'],
    ['vordruck', '.hidden.pdf'],
    ['vordruck', 'estab 42 A.php'],
] as [$area, $filename]) {
    $assertRejected(
        static fn () => estab_file_validate_name($area, $filename),
        'unsafe filename accepted: ' . $filename
    );
}
$assertRejected(
    static fn () => estab_file_area('backup'),
    'unknown storage area accepted'
);

$downloadUrl = estab_file_download_url('/4fach/download.php', 'attachment', 'EL0001.pdf');
$assert(
    $downloadUrl === '/4fach/download.php?area=attachment&file=EL0001.pdf',
    'attachment download URL changed unexpectedly'
);
$formUrl = estab_file_download_url('/4fach/download.php', 'vordruck', 'estab 42 A.pdf');
$assert(str_contains($formUrl, 'file=estab%2042%20A.pdf'), 'generated-form URL is not RFC3986 encoded');
$disposition = estab_file_content_disposition("Lage\r\nX-Evil: yes.pdf", false);
$assert(!str_contains($disposition, "\r") && !str_contains($disposition, "\n"), 'header injection survived disposition');
$assert(str_starts_with($disposition, 'attachment;'), 'attachments are not forced to download');

$temporaryBase = sys_get_temp_dir() . '/estab-file-access-' . bin2hex(random_bytes(8));
$allowedRoot = $temporaryBase . '/vordruck';
$outsideRoot = $temporaryBase . '/outside';
mkdir($allowedRoot, 0700, true);
mkdir($outsideRoot, 0700, true);
file_put_contents($allowedRoot . '/estab 1 A.pdf', '%PDF-test');
file_put_contents($allowedRoot . '/estab 2 E.png', 'not-an-image');
file_put_contents($allowedRoot . '/ignored.php', '<?php');
file_put_contents($outsideRoot . '/secret.pdf', 'secret');
$linkCreated = @symlink($outsideRoot . '/secret.pdf', $allowedRoot . '/escape.pdf');

try {
    $resolved = estab_file_resolve($allowedRoot, 'vordruck', 'estab 1 A.pdf');
    $assert($resolved === realpath($allowedRoot . '/estab 1 A.pdf'), 'allowed file resolved incorrectly');
    $opened = estab_file_open($allowedRoot, 'vordruck', 'estab 1 A.pdf');
    $assert(
        is_resource($opened) && stream_get_contents($opened) === '%PDF-test',
        'safe file opener changed the authorized bytes'
    );
    fclose($opened);

    $opened = estab_file_open($allowedRoot, 'vordruck', 'estab 1 A.pdf');
    fseek($opened, 2);
    $position = ftell($opened);
    $mime = estab_file_stream_content_type($opened);
    $assert(
        $mime === 'application/pdf' && ftell($opened) === $position,
        'stream MIME detection changed the authorized handle position'
    );
    fclose($opened);

    $opened = estab_file_open($allowedRoot, 'vordruck', 'estab 1 A.pdf');
    rename(
        $allowedRoot . '/estab 1 A.pdf',
        $allowedRoot . '/estab original A.pdf'
    );
    file_put_contents($allowedRoot . '/estab 1 A.pdf', '%PDF-replacement');
    $assert(
        stream_get_contents($opened) === '%PDF-test',
        'opened handle followed a later pathname replacement'
    );
    fclose($opened);
    unlink($allowedRoot . '/estab 1 A.pdf');
    rename(
        $allowedRoot . '/estab original A.pdf',
        $allowedRoot . '/estab 1 A.pdf'
    );
    $assertRejected(
        static fn () => estab_file_resolve($allowedRoot, 'vordruck', '../outside/secret.pdf'),
        'path traversal escaped the storage root'
    );
    if ($linkCreated) {
        $assertRejected(
            static fn () => estab_file_resolve($allowedRoot, 'vordruck', 'escape.pdf'),
            'symlink escaped the storage root'
        );
    }

    $listed = estab_file_list($allowedRoot, 'vordruck');
    $listedNames = array_column($listed, 'name');
    sort($listedNames);
    $assert(
        $listedNames === ['estab 1 A.pdf', 'estab 2 E.png'],
        'safe list includes invalid, linked or non-form files'
    );
} finally {
    if ($linkCreated) {
        unlink($allowedRoot . '/escape.pdf');
    }
    unlink($allowedRoot . '/estab 1 A.pdf');
    unlink($allowedRoot . '/estab 2 E.png');
    unlink($allowedRoot . '/ignored.php');
    unlink($outsideRoot . '/secret.pdf');
    rmdir($allowedRoot);
    rmdir($outsideRoot);
    rmdir($temporaryBase);
}

$root = dirname(__DIR__, 2);
$apache = (string) file_get_contents($root . '/docker/apache/estab.conf');
$download = (string) file_get_contents($root . '/4fach/download.php');
$attachmentIntegrity = (string) file_get_contents(
    $root . '/app/attachment_integrity.php'
);
$preview = (string) file_get_contents($root . '/4fach/showpic.php');
$emailPreview = (string) file_get_contents($root . '/4fach/email.php');
$forms = (string) file_get_contents($root . '/4fach/vordrucke.php');
$menu = (string) file_get_contents($root . '/menue.inc.php');
$attachmentController = (string) file_get_contents($root . '/4fach/anhang.php');
$pdf = (string) file_get_contents($root . '/4fbak/backup_pdf.php');

$assert(
    preg_match('~<Directory /var/www/html/4fdata>.*?Require all denied.*?</Directory>~s', $apache) === 1,
    'Apache still exposes 4fdata directly'
);
foreach ([$download, $preview, $emailPreview, $forms] as $endpoint) {
    $assert(
        str_contains($endpoint, 'estab_read_session_identity'),
        'file endpoint lacks the authenticated-session boundary'
    );
}
$assert(
    str_contains($preview, '<= 16000000')
        && !str_contains($preview, '<= 40000000')
        && str_contains(
            $preview,
            '$previewByteLimit = 24 * 1024 * 1024;'
        )
        && strpos($preview, '$snapshotSize <= $previewByteLimit')
            < strpos($preview, 'stream_get_contents ($stream)'),
    'automatic image preview can exhaust small NAS workers with 40-megapixel decodes'
);
$assert(
    str_contains($preview, 'session_write_close ();')
        && str_contains($download, 'session_write_close();')
        && str_contains($emailPreview, 'session_write_close();')
        && strpos($preview, 'session_write_close ();')
            < strpos($preview, 'estab_attachment_connection (')
        && strpos($download, 'session_write_close();')
            < strpos($download, 'estab_auth_connect(')
        && strpos($emailPreview, 'session_write_close();')
            < strpos($emailPreview, 'estab_attachment_connection('),
    'preview, email rendering or download keeps the PHP session locked during heavy file work'
);
$assert(
    str_contains($download, 'estab_file_open')
        && str_contains(
            $download,
            'estab_attachment_integrity_open_snapshot('
        )
        && str_contains($download, 'begin_transaction()')
        && str_contains($download, 'estab_file_stream_content_type($stream)')
        && substr_count($download, 'estab_read_attachment(') === 2
        && str_contains(
            $download,
            'estab_read_attachment_authorization_version('
        )
        && strpos(
            $download,
            'Could not commit initial attachment authorization'
        ) < strpos($download, 'estab_attachment_integrity_open_snapshot(')
        && strpos($download, 'estab_attachment_integrity_open_snapshot(')
            < strpos(
                $download,
                '$currentAttachment = estab_read_attachment('
            )
        && str_contains(
            $attachmentIntegrity,
            'estab_attachment_integrity_measure_stream($snapshot)'
        )
        && str_contains($attachmentIntegrity, "'stream' => \$snapshot")
        && str_contains($download, 'X-Content-Type-Options: nosniff')
        && str_contains($download, 'X-eStab-Attachment-Integrity: ')
        && str_contains($download, 'Content-Disposition: '),
    'download endpoint lacks an exact verified snapshot or safe response headers'
);
$assert(
    substr_count($preview, 'estab_read_attachment (') === 2
        && str_contains(
            $preview,
            'estab_read_attachment_authorization_version ('
        )
        && strpos(
            $preview,
            'Could not commit initial attachment preview authorization'
        ) < strpos(
            $preview,
            'estab_attachment_integrity_open_snapshot ('
        )
        && strpos(
            $preview,
            'estab_attachment_integrity_open_snapshot ('
        ) < strpos(
            $preview,
            '$currentAttachment = estab_read_attachment ('
        ),
    'image preview holds operational database locks while hashing file bytes'
);
$assert(
    preg_match(
        '/\$currentAttachment\s*=\s*estab_read_attachment\s*\('
            . '.*?\$readIdentity,\s*true,\s*'
            . '\$currentAttachmentWriteScope\s*\);/s',
        $download
    ) === 1
        && preg_match(
            '/\$currentAttachment\s*=\s*estab_read_attachment\s*\('
                . '.*?\$readIdentity,\s*true,\s*'
                . '\$currentAttachmentWriteScope\s*\);/s',
            $preview
        ) === 1,
    'final attachment authorization lacks locked reads or exact write scope'
);
$inlineMimeMatch = [];
$inlineMimeFound = preg_match(
    '/\$attachmentInlineMimeTypes\s*=\s*\[(?<values>.*?)\];/s',
    $download,
    $inlineMimeMatch
) === 1;
$inlineMimeTypes = [];
if ($inlineMimeFound) {
    $mimeValues = [];
    preg_match_all(
        "/'([^']+)'/",
        (string) ($inlineMimeMatch['values'] ?? ''),
        $mimeValues
    );
    $inlineMimeTypes = $mimeValues[1] ?? [];
}
$mimeDetectionPosition = strpos(
    $download,
    '$contentType = estab_file_stream_content_type($stream);'
);
$inlineDecisionPosition = strpos(
    $download,
    'in_array($contentType, $attachmentInlineMimeTypes, true)'
);
$inlineDispositionPosition = strpos(
    $download,
    "header('Content-Disposition: '"
);
$assert(
    str_contains($download, "array_key_exists('view', \$_GET)")
        && str_contains(
            $download,
            "\$view !== 'inline'"
        )
        && str_contains(
            $download,
            "\$area !== 'attachment'"
        )
        && str_contains(
            $download,
            "\$attachmentInlineRequested = \$area === 'attachment' "
                . "&& \$view === 'inline';"
        )
        && $inlineMimeFound
        && $inlineMimeTypes === [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/bmp',
            'image/x-ms-bmp',
        ]
        && is_int($mimeDetectionPosition)
        && is_int($inlineDecisionPosition)
        && is_int($inlineDispositionPosition)
        && $mimeDetectionPosition < $inlineDecisionPosition
        && $inlineDecisionPosition < $inlineDispositionPosition
        && substr_count($download, '$inline = true;') === 1
        && strpos($download, '$inline = true;') > $mimeDetectionPosition
        && str_contains(
            $download,
            "\$inline = \$area === 'vordruck'"
        )
        && str_contains($download, "Cache-Control: private, no-store")
        && str_contains(
            $download,
            "Content-Security-Policy: frame-ancestors 'self'"
        )
        && str_contains($download, 'X-Frame-Options: SAMEORIGIN')
        && str_contains(
            $download,
            "Content-Security-Policy: sandbox; default-src 'none'; "
                . "frame-ancestors 'none'"
        )
        && str_contains($download, 'X-Frame-Options: DENY')
        && str_contains($download, 'X-Content-Type-Options: nosniff'),
    'attachment inline mode is not an exact opt-in after verified MIME '
        . 'detection or weakens normal download headers'
);
$assert(
    str_contains(
        $preview,
        'estab_attachment_integrity_open_snapshot ('
    )
        && str_contains($preview, 'begin_transaction ()')
        && substr_count($preview, 'estab_read_attachment (') === 2
        && str_contains($preview, '$requested,')
        && str_contains(
            $preview,
            'estab_read_attachment_authorization_version ('
        )
        && str_contains($preview, 'getimagesizefromstring ($imageBytes)')
        && str_contains($preview, 'X-eStab-Attachment-Integrity: ')
        && substr_count($preview, 'X-Content-Type-Options: nosniff') >= 2
        && !str_contains($preview, 'realpath ($requested)'),
    'preview endpoint lacks verified snapshot authorization or safe decoding'
);
$assert(
    str_contains(
        $emailPreview,
        'estab_attachment_integrity_open_snapshot('
    )
        && str_contains($emailPreview, 'estab_email_attachment_parse_stream(')
        && substr_count($emailPreview, 'estab_read_attachment(') === 2
        && str_contains(
            $emailPreview,
            'estab_read_attachment_authorization_version('
        )
        && str_contains($emailPreview, "strtolower(pathinfo(\$requested, PATHINFO_EXTENSION)) !== 'eml'")
        && str_contains($emailPreview, 'X-eStab-Email-Rendering: passive-text')
        && str_contains($emailPreview, "script-src 'none'")
        && str_contains($emailPreview, "img-src 'none'")
        && str_contains($emailPreview, "frame-ancestors 'self'")
        && str_contains($emailPreview, 'X-Frame-Options: SAMEORIGIN')
        && str_contains($emailPreview, 'Originaldatei herunterladen')
        && !str_contains($emailPreview, "\$parsed['body_html']"),
    'email preview lacks verified object authorization, passive escaped rendering or original download'
);
$assert(
    str_contains($download, 'estab_read_attachment(')
        && str_contains($download, 'estab_read_message_allowed(')
        && str_contains(
            $forms,
            'estab_read_filter_generated_forms_for_incident('
        ),
    'file endpoints lack object-level attachment or message authorization'
);
/*
 * Die Anhangtabelle baut ihre Ziele ueber den Ausgabeweg, nicht ueber einen
 * Pfad in die Ablage. Sie steht seit der Umstellung auf das Tabellenbauteil
 * in app/anhang_tabelle.php; die Steuerung darf den Ablagepfad weiterhin
 * nirgends verwenden.
 */
$attachmentTable = (string) file_get_contents($root . '/app/anhang_tabelle.php');
$assert(
    str_contains($attachmentTable, 'estab_file_download_url')
        && !str_contains($attachmentTable, '$conf_4f ["ablage_uri"]')
        && !str_contains($attachmentController, '$conf_4f ["ablage_uri"]'),
    'attachment table still links directly into 4fdata'
);
$assert(
    str_contains($attachmentTable, "'file' => \$z['wert']")
        && !str_contains($attachmentTable, "\$conf_4f['ablage_dir']")
        && !str_contains($attachmentController, '$conf_4f ["ablage_dir"]."/".$attachmentValue'),
    'attachment preview still sends an absolute filesystem path'
);
$assert(
    str_contains($pdf, 'estab_file_download_url')
        && !str_contains($pdf, '$link = "../anhang/"'),
    'generated PDF still embeds a direct attachment path'
);
$formsCardMatch = [];
$formsCardFound = preg_match(
    '/\\$menue\\[(\\d+)\\]\\["navigation_key"\\]\\s*=\\s*"forms"\\s*;/',
    $menu,
    $formsCardMatch
) === 1;
$formsCardIndex = $formsCardFound ? $formsCardMatch[1] : '';
$assert(
    $formsCardFound
        && str_contains(
            $menu,
            '$menue[' . $formsCardIndex
                . ']["link"] = "./4fach/vordrucke.php";'
        )
        && str_contains(
            $menu,
            '$menue[' . $formsCardIndex . ']["access"] = "application";'
        )
        && str_contains(
            $menu,
            '$menue[' . $formsCardIndex . ']["visible"] = true'
        ),
    'secure canonical generated-form menu item is not active'
);

foreach (['4fach/4fachform.php', '4fueltg/ue_ltg.php'] as $legacyView) {
    $source = (string) file_get_contents($root . '/' . $legacyView);
    $assert(str_contains($source, 'estab_file_download_url'), $legacyView . ' lacks secure download links');
    $assert(!str_contains($source, '$conf_4f ["ablage_uri"]'), $legacyView . ' still exposes 4fdata');
}

echo "file access security: OK ({$assertions} assertions)\n";
