<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/attachment.php';

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
    } catch (InvalidArgumentException | OverflowException) {
        $rejected = true;
    }
    $assert($rejected, $message);
};

$assert(estab_attachment_validate_prefix(' el ') === 'EL', 'authority prefix is normalised');
$assertRejected(
    static fn () => estab_attachment_validate_prefix('EL.*'),
    'regular-expression syntax is rejected in authority prefix'
);
$assert(estab_attachment_validate_session_id('abcDEF0123,-_') === 'abcDEF0123,-_', 'safe session id accepted');
$assertRejected(
    static fn () => estab_attachment_validate_session_id("session\nOR 1=1"),
    'unsafe session id rejected'
);
$assert(
    estab_attachment_validate_reservation_name('EL0001', 'EL') === 'EL0001',
    'EL0001-style reservation accepted'
);
$assertRejected(
    static fn () => estab_attachment_validate_reservation_name('EL001', 'EL'),
    'reservation requires at least four sequence digits'
);
$assertRejected(
    static fn () => estab_attachment_validate_reservation_name('XX0001', 'EL'),
    'reservation is bound to configured authority prefix'
);
$assertRejected(
    static fn () => estab_attachment_validate_reservation_name('../EL0001', 'EL'),
    'reservation path traversal rejected'
);

$assert(estab_attachment_next_name('EL', 0) === 'EL0001', 'empty sequence begins at EL0001');
$assert(estab_attachment_next_name('EL', 41) === 'EL0042', 'sequence is zero padded');
$assert(estab_attachment_next_name('EL', 9999) === 'EL10000', 'sequence grows beyond four digits');
$assertRejected(
    static fn () => estab_attachment_next_name('EL', -1),
    'negative sequence rejected'
);
$assertRejected(
    static fn () => estab_attachment_next_name('EL', 0, 3),
    'width below EL0001 format rejected'
);
$assertRejected(
    static fn () => estab_attachment_next_name('EL', PHP_INT_MAX),
    'integer sequence overflow rejected'
);

$assert(
    estab_attachment_parse_tactical_time('221530JUL2026') === '2026-07-22 15:30:00',
    'English tactical timestamp parsed'
);
$assert(
    estab_attachment_parse_tactical_time('312359DEZ2026') === '2026-12-31 23:59:00',
    'German tactical month parsed'
);
$assert(
    estab_attachment_parse_tactical_time('290130FEB2024') === '2024-02-29 01:30:00',
    'valid leap day parsed'
);
$assert(
    estab_attachment_parse_tactical_time('311259DEC1999') === '1999-12-31 12:59:00',
    'historical SQL-range timestamp remains supported'
);
$assert(estab_attachment_parse_tactical_time('290130FEB2023') === null, 'invalid calendar day rejected');
$assert(estab_attachment_parse_tactical_time('312460DEZ2026') === null, 'invalid tactical time rejected');
$assert(estab_attachment_validate_sql_datetime('2026-07-22 15:30:00'), 'strict SQL datetime accepted');
$assert(!estab_attachment_validate_sql_datetime('2026-02-29 15:30:00'), 'normalised invalid SQL date rejected');
$assert(!estab_attachment_validate_sql_datetime('0999-12-31 23:59:00'), 'date below SQL range rejected');

$metadata = estab_attachment_validate_metadata([
    'filename' => '/srv/attachments/EL0001.PDF',
    'org_filename' => 'C:\\fakepath\\Lageplan.PDF',
    'comment' => '  Lage <Nord>  ',
    'kuerzel' => 'attacker-controlled',
    'time' => '2026-07-22 15:30:00',
    'md5hash' => 'ABCDEF0123456789ABCDEF0123456789',
], 'EL0001', ' ADA ');
$assert($metadata['filename'] === 'EL0001', 'stored base name is bound to reservation');
$assert($metadata['fileext'] === 'pdf', 'stored extension normalised');
$assert($metadata['org_filename'] === 'Lageplan.PDF', 'browser path removed from original filename');
$assert($metadata['comment'] === 'Lage <Nord>', 'comment trimmed without corrupting text');
$assert($metadata['kuerzel'] === 'ada', 'session code overrides submitted metadata');
$assert($metadata['md5hash'] === 'abcdef0123456789abcdef0123456789', 'digest normalised');
$assert(estab_attachment_extension_is_allowed('PDF'), 'supported extension accepted case-insensitively');
$assert(!estab_attachment_extension_is_allowed('php'), 'executable extension rejected');

$invalidMetadata = [
    'filename' => 'EL0001.pdf',
    'org_filename' => 'lage.pdf',
    'comment' => 'Lage',
    'time' => '2026-07-22 15:30:00',
    'md5hash' => 'abcdef0123456789abcdef0123456789',
];
$sixCharacterCode = estab_attachment_validate_metadata($invalidMetadata, 'EL0001', 'abc123');
$assert($sixCharacterCode['kuerzel'] === 'abc123', 'six-character login code accepted for attachments');
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['filename' => 'EL0002.pdf']),
        'EL0001',
        'ada'
    ),
    'stored filename cannot switch reservations'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['filename' => 'EL0001.php']),
        'EL0001',
        'ada'
    ),
    'unsupported stored extension rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['org_filename' => "lage\0.pdf"]),
        'EL0001',
        'ada'
    ),
    'control characters in original filename rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['comment' => "Lage\nNord"]),
        'EL0001',
        'ada'
    ),
    'control characters in comment rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata($invalidMetadata, 'EL0001', 'toolong'),
    'session code exceeding schema rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['time' => '2026-02-29 15:30:00']),
        'EL0001',
        'ada'
    ),
    'invalid metadata timestamp rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['md5hash' => 'not-a-digest']),
        'EL0001',
        'ada'
    ),
    'invalid metadata digest rejected'
);

$escaped = estab_attachment_html('<a href="x">\'&');
$assert($escaped === '&lt;a href=&quot;x&quot;&gt;&#039;&amp;', 'HTML text and attributes escaped');
$assert(estab_attachment_table('nv_anhang') === '`nv_anhang`', 'configured table identifier quoted');
$assertRejected(
    static fn () => estab_attachment_table('nv_anhang; DROP TABLE nv_anhang'),
    'SQL in table identifier rejected'
);
$assert(estab_attachment_database_error_is_retryable(1062), 'duplicate-key conflict is retryable');
$assert(estab_attachment_database_error_is_retryable(1205), 'lock timeout is retryable');
$assert(estab_attachment_database_error_is_retryable(1213), 'deadlock is retryable');
$assert(!estab_attachment_database_error_is_retryable(1048), 'invalid data error is not retried');

$attachmentSource = file_get_contents(__DIR__ . '/../../app/attachment.php');
$controllerSource = file_get_contents(__DIR__ . '/../../4fach/anhang.php');
$messageFormSource = file_get_contents(__DIR__ . '/../../4fach/4fachform.php');
$schemaSource = file_get_contents(__DIR__ . '/../../docker/db/init/10-schema.sql');
$verifySource = file_get_contents(__DIR__ . '/../../docker/db/verify.sql');
$assert(
    is_string($attachmentSource)
        && is_string($controllerSource)
        && is_string($messageFormSource),
    'attachment and message-form sources readable'
);
$assert(is_string($schemaSource) && is_string($verifySource), 'container schema checks readable');
$assert(
    preg_match('/(?:mysql_query|query_table(?:_iu)?|->query\s*\()/i', $attachmentSource . $controllerSource) !== 1,
    'active attachment path contains no direct SQL execution'
);
$assert(
    substr_count($attachmentSource, 'estab_attachment_statement_result(') >= 3
        && substr_count($attachmentSource, 'estab_attachment_statement_row(') >= 4
        && substr_count($attachmentSource, '->get_result()') === 1
        && substr_count($attachmentSource, '->fetch_assoc()') === 1,
    'all attachment SELECT results are validated before they are consumed'
);
$assert(
    str_contains($attachmentSource, '$statement->errno ?: $connection->errno'),
    'deferred result errors preserve their retryable MariaDB error code'
);
$assert(str_contains($attachmentSource, 'begin_transaction()'), 'reservation starts a transaction');
$assert(substr_count($attachmentSource, 'FOR UPDATE') >= 2, 'reservation candidates are locked');
$assert(
    str_contains($attachmentSource, 'WHERE `filename` = ? AND `status` = 8 AND `id` = ?'),
    'claim requires exact active reservation and owner'
);
$assert(
    str_contains($attachmentSource, 'WHERE `filename` = ? AND `status` = 2 AND `id` = ?'),
    'finalisation requires exact claimed filename and owner'
);
$assert(
    str_contains($schemaSource, 'UNIQUE KEY `uq_anhang_filename` (`filename`)'),
    'schema provides unique filename race guard'
);
$assert(
    str_contains($schemaSource, '`kuerzel` VARCHAR(6) NULL DEFAULT NULL')
        && str_contains($schemaSource, 'MODIFY COLUMN `kuerzel` VARCHAR(6) NULL DEFAULT NULL'),
    'fresh and existing attachment schemas support six-character login codes'
);
$assert(
    str_contains($verifySource, 'seq_in_index = 1')
        && substr_count($verifySource, "index_name = 'uq_anhang_filename'") >= 2,
    'schema verification requires an exact single-column filename index'
);
$assert(
    str_contains($controllerSource, 'estab_csrf_field ()')
        && str_contains($controllerSource, 'estab_csrf_require_post ($_SERVER, $_POST)')
        && str_contains($controllerSource, 'session_status () === PHP_SESSION_NONE'),
    'active upload form and handler enforce CSRF with a safe session guard'
);
$assert(
    str_contains($controllerSource, 'function estab_attachment_post_scalar')
        && str_contains($controllerSource, 'array_key_exists ($key, $post)')
        && str_contains($controllerSource, 'is_string ($post [$key])')
        && substr_count($controllerSource, 'estab_attachment_post_scalar ($_POST,') >= 22,
    'attachment form state does not safely accept missing or non-scalar browser controls'
);
$assert(
    str_contains($controllerSource, '[1-5][1-4])_gn')
        && str_contains($controllerSource, '(?:_(bl))?')
        && str_contains($controllerSource, '$recipientPattern')
        && !str_contains($controllerSource, 'preg_match ("/\\\\A([^_]+)_([^_]+)_([^_]+)\\\\z/D"'),
    'attachment recipient restore is not constrained to real matrix positions and copy colours'
);
$assert(
    str_contains($controllerSource, '($formdata ["10_anschrift"] ?? "") === ""')
        && str_contains($messageFormSource, 'value=\\"16_".$m.$n."_bl\\"')
        && str_contains($messageFormSource, 'if (!$this->feld[17])')
        && str_contains($messageFormSource, 'name=\\"17_vermerke\\"'),
    'returned A/W attachment form can lose address, blue/green selection or sighter notes'
);
$assert(
    preg_match('/estab_attachment_html\s*\(\s*\$file\s*\[\s*"comment"/', $controllerSource) === 1,
    'database comment is escaped at HTML boundary'
);
$assert(
    preg_match('/estab_attachment_html\s*\(\s*\$storedFilename/', $controllerSource) === 1,
    'database filename is escaped at HTML boundary'
);
$assert(
    str_contains($controllerSource, 'Liste der verfügbaren Dateien'),
    'attachment list heading is stored as valid UTF-8'
);
$assert(
    !str_contains($controllerSource, 'Liste der verfÃ¼gbaren Dateien'),
    'attachment list heading contains no UTF-8 mojibake'
);
$assert(
    str_contains($controllerSource, 'Kein Menüpunkt'),
    'attachment fallback message is stored as valid UTF-8'
);

foreach (['../../4fach/upload.php', '../../4fach/upload/upload.php'] as $legacyPath) {
    $legacySource = file_get_contents(__DIR__ . '/' . $legacyPath);
    $classPosition = is_string($legacySource) ? strpos($legacySource, 'class fileupload') : false;
    $disabledPrefix = $classPosition === false ? '' : substr($legacySource, 0, $classPosition);
    $assert(
        str_contains($disabledPrefix, 'http_response_code (410)') && str_contains($disabledPrefix, 'exit;'),
        basename($legacyPath) . ' is disabled before legacy SQL can execute'
    );
}

printf("attachment security: OK (%d assertions)\n", $assertions);
