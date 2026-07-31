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
    'sha256' => str_repeat('A1', 32),
    'size' => 1234,
], 'EL0001', ' ADA ');
$assert($metadata['filename'] === 'EL0001', 'stored base name is bound to reservation');
$assert($metadata['fileext'] === 'pdf', 'stored extension normalised');
$assert($metadata['org_filename'] === 'Lageplan.PDF', 'browser path removed from original filename');
$assert($metadata['comment'] === 'Lage <Nord>', 'comment trimmed without corrupting text');
$assert($metadata['kuerzel'] === 'ada', 'session code overrides submitted metadata');
$assert($metadata['md5hash'] === 'abcdef0123456789abcdef0123456789', 'digest normalised');
$assert($metadata['sha256'] === str_repeat('a1', 32), 'SHA-256 normalised');
$assert($metadata['size'] === 1234, 'byte length retained');
$assert(estab_attachment_extension_is_allowed('PDF'), 'supported extension accepted case-insensitively');
$assert(estab_attachment_extension_is_allowed('JPEG'), 'JPEG alias accepted case-insensitively');
$assert(estab_attachment_extension_is_allowed('TIFF'), 'TIFF alias matches the delivery allowlist');
$assert(!estab_attachment_extension_is_allowed('php'), 'executable extension rejected');

$jpegMetadata = estab_attachment_validate_metadata([
    'filename' => '/srv/attachments/EL0001.JPEG',
    'org_filename' => 'C:\\fakepath\\Lagebild.JPEG',
    'comment' => 'Lagebild',
    'time' => '2026-07-22 15:30:00',
    'md5hash' => 'abcdef0123456789abcdef0123456789',
    'sha256' => str_repeat('ab', 32),
    'size' => 2048,
], 'EL0001', 'ada');
$assert($jpegMetadata['fileext'] === 'jpeg', 'JPEG alias is stored in canonical lowercase');
$assert($jpegMetadata['org_filename'] === 'Lagebild.JPEG', 'JPEG original name is preserved');

$invalidMetadata = [
    'filename' => 'EL0001.pdf',
    'org_filename' => 'lage.pdf',
    'comment' => 'Lage',
    'time' => '2026-07-22 15:30:00',
    'md5hash' => 'abcdef0123456789abcdef0123456789',
    'sha256' => str_repeat('cd', 32),
    'size' => 42,
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
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['sha256' => 'not-a-sha256']),
        'EL0001',
        'ada'
    ),
    'invalid metadata SHA-256 rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['size' => '42']),
        'EL0001',
        'ada'
    ),
    'non-integer metadata byte length rejected'
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

$integrityFixtureRoot = sys_get_temp_dir()
    . '/estab-attachment-download-' . bin2hex(random_bytes(8));
$integrityFixturePath = $integrityFixtureRoot . '/EL0001.txt';
$integrityOriginalPath = $integrityFixturePath . '.original';
$integrityPayload = "verified attachment download fixture\n";
$integrityStream = null;
$legacyStream = null;
if (!mkdir($integrityFixtureRoot, 0700, true)) {
    throw new RuntimeException('Could not create attachment download fixture');
}
try {
    if (
        file_put_contents($integrityFixturePath, $integrityPayload)
            !== strlen($integrityPayload)
    ) {
        throw new RuntimeException('Could not write attachment download fixture');
    }
    $integrityRow = [
        'integrity_required' => 1,
        'ingest_sha256' => hash('sha256', $integrityPayload),
        'ingest_size' => strlen($integrityPayload),
        'integrity_captured_at' => '2026-07-30 12:00:00.000001',
    ];
    $opened = estab_attachment_integrity_open_snapshot(
        $integrityRow,
        $integrityFixtureRoot,
        'EL0001.txt'
    );
    $integrityStream = $opened['stream'];
    $assert(
        is_resource($integrityStream)
            && $opened['state'] === 'verified'
            && $opened['content_size'] === strlen($integrityPayload)
            && $opened['sha256'] === hash('sha256', $integrityPayload),
        'verified attachment snapshot has invalid integrity metadata'
    );

    if (
        !rename($integrityFixturePath, $integrityOriginalPath)
        || file_put_contents(
            $integrityFixturePath,
            str_repeat('X', strlen($integrityPayload))
        ) !== strlen($integrityPayload)
    ) {
        throw new RuntimeException('Could not replace attachment pathname fixture');
    }
    $assert(
        stream_get_contents($integrityStream) === $integrityPayload,
        'verified download handle followed a later pathname replacement'
    );
    fclose($integrityStream);
    $integrityStream = null;
    unlink($integrityFixturePath);
    rename($integrityOriginalPath, $integrityFixturePath);

    $tamperedPayload = str_repeat('T', strlen($integrityPayload));
    file_put_contents($integrityFixturePath, $tamperedPayload);
    $tamperRejected = false;
    try {
        $unexpected = estab_attachment_integrity_open_snapshot(
            $integrityRow,
            $integrityFixtureRoot,
            'EL0001.txt'
        );
        fclose($unexpected['stream']);
    } catch (EstabAttachmentIntegrityException) {
        $tamperRejected = true;
    }
    $assert(
        $tamperRejected,
        'same-size attachment tampering produced a verified download snapshot'
    );

    file_put_contents($integrityFixturePath, $integrityPayload);
    $legacy = estab_attachment_integrity_open_snapshot(
        [
            'integrity_required' => 0,
            'ingest_sha256' => null,
            'ingest_size' => null,
            'integrity_captured_at' => null,
        ],
        $integrityFixtureRoot,
        'EL0001.txt'
    );
    $legacyStream = $legacy['stream'];
    $assert(
        $legacy['state'] === 'legacy_unverifiable'
            && $legacy['statement']
                === 'Integrität beim Eingang nicht belegbar'
            && $legacy['sha256'] === null
            && $legacy['content_size'] === strlen($integrityPayload)
            && stream_get_contents($legacyStream) === $integrityPayload,
        'legacy download snapshot invented an ingest proof or changed bytes'
    );
} finally {
    if (is_resource($integrityStream)) {
        fclose($integrityStream);
    }
    if (is_resource($legacyStream)) {
        fclose($legacyStream);
    }
    if (is_file($integrityFixturePath)) {
        unlink($integrityFixturePath);
    }
    if (is_file($integrityOriginalPath)) {
        unlink($integrityOriginalPath);
    }
    rmdir($integrityFixtureRoot);
}

$attachmentSource = file_get_contents(__DIR__ . '/../../app/attachment.php');
$integritySource = file_get_contents(
    __DIR__ . '/../../app/attachment_integrity.php'
);
$downloadSource = file_get_contents(__DIR__ . '/../../4fach/download.php');
$controllerSource = file_get_contents(__DIR__ . '/../../4fach/anhang.php');
$messageFormSource = file_get_contents(__DIR__ . '/../../4fach/4fachform.php');
$schemaSource = file_get_contents(__DIR__ . '/../../docker/db/init/10-schema.sql');
$verifySource = file_get_contents(__DIR__ . '/../../docker/db/verify.sql');
$integrityMigration = file_get_contents(
    __DIR__ . '/../../docker/db/migrations/95-attachment-ingest-integrity.sql'
);
$assert(
    is_string($attachmentSource)
        && is_string($integritySource)
        && is_string($downloadSource)
        && is_string($controllerSource)
        && is_string($messageFormSource),
    'attachment and message-form sources readable'
);
$assert(
    preg_match(
        '/function estab_attachment_find_for_incident\s*\(.*?SELECT .*?'
            . '`integrity_required`.*?`ingest_sha256`.*?`ingest_size`.*?'
            . '`integrity_captured_at`.*?WHERE `filename` = \?/s',
        $attachmentSource
    ) === 1,
    'attachment lookup omits immutable ingest-integrity evidence'
);
$assert(
    str_contains(
        $integritySource,
        'function estab_attachment_integrity_open_snapshot('
    )
        && str_contains($integritySource, '$snapshot = tmpfile();')
        && str_contains(
            $integritySource,
            'stream_copy_to_stream($source, $snapshot)'
        )
        && str_contains(
            $integritySource,
            'estab_attachment_integrity_measure_stream($snapshot)'
        )
        && str_contains($downloadSource, '$attachmentIntegrity =')
        && str_contains(
            $downloadSource,
            'estab_attachment_integrity_open_snapshot('
        )
        && str_contains(
            $downloadSource,
            "X-eStab-Attachment-Integrity: "
        )
        && str_contains(
            $downloadSource,
            "X-eStab-Attachment-SHA256: "
        ),
    'attachment download does not stream the exact verified private snapshot'
);
$assert(
    is_string($schemaSource)
        && is_string($verifySource)
        && is_string($integrityMigration),
    'container schema checks readable'
);
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
$attachmentFindStart = strpos(
    $attachmentSource,
    'function estab_attachment_find('
);
$attachmentFindEnd = strpos(
    $attachmentSource,
    '/** Prepared audit insert',
    $attachmentFindStart === false ? 0 : $attachmentFindStart
);
$attachmentFindWrapper = (
    $attachmentFindStart !== false
    && $attachmentFindEnd !== false
    && $attachmentFindEnd > $attachmentFindStart
) ? substr(
    $attachmentSource,
    $attachmentFindStart,
    $attachmentFindEnd - $attachmentFindStart
) : '';
$assert(
    str_contains(
        $attachmentSource,
        ". (\$forUpdate ? ' FOR UPDATE' : '')"
    )
        && $attachmentFindWrapper !== ''
        && strpos(
            $attachmentFindWrapper,
            'estab_attachment_validate_reservation_name($filename)'
        ) < strpos(
            $attachmentFindWrapper,
            'estab_incident_require_active($connection, true)'
        ),
    'attachment compatibility lookup does not validate first or honor its row-lock flag'
);
$assert(
    substr_count(
        $attachmentSource,
        'estab_incident_lock_command_post_for_write($connection, $incident);'
    ) >= 2,
    'reservation or upload accepts an active incident without a command-post name'
);
$assert(
    str_contains($attachmentSource, 'WHERE `filename` = ? AND `status` = 8 AND `id` = ?'),
    'claim requires exact active reservation and owner'
);
$assert(
    str_contains($attachmentSource, 'WHERE `filename` = ? AND `status` = 2 AND `id` = ?'),
    'finalisation requires exact claimed filename and owner'
);
$assert(
    str_contains($attachmentSource, 'function estab_attachment_store_upload(')
        && str_contains(
            $attachmentSource,
            'SAVEPOINT estab_attachment_before_claim'
        )
        && str_contains(
            $attachmentSource,
            'ROLLBACK TO SAVEPOINT estab_attachment_before_claim'
        )
        && str_contains(
            $attachmentSource,
            'Could not commit atomic upload'
        )
        && str_contains(
            $controllerSource,
            'estab_attachment_store_upload ('
        ),
    'browser upload does not hold the active incident across file and DB finalisation'
);
$assert(
    str_contains($controllerSource, 'estab_attachment_integrity_measure_file (')
        && str_contains($attachmentSource, '`ingest_sha256` = ?')
        && str_contains($attachmentSource, '`ingest_size` = ?')
        && str_contains(
            $attachmentSource,
            '`integrity_captured_at` = NOW(6)'
        )
        && str_contains(
            (string) $integrityMigration,
            'Final attachment integrity evidence is immutable'
        ),
    'upload finalisation lacks immutable SHA-256/size ingest evidence'
);
$assert(
    str_contains(
        $attachmentSource,
        "SET `status` = 4, `id` = ''"
    )
        && str_contains(
            $attachmentSource,
            'Could not release failed upload'
        )
        && !str_contains(
            $controllerSource,
            '$my_upload->claim_reservation ($new_name)'
        ),
    'failed upload can leave a claimed reservation across an incident switch'
);
$assert(
    str_contains($controllerSource, 'if (!$finalized && $new_name !== "")')
        && str_contains($controllerSource, 'estab_attachment_release (')
        && str_contains(
            $controllerSource,
            'eStab attachment reservation cleanup failed: '
        ),
    'controller releases uploads rejected before the atomic store'
);
$assert(
    str_contains($schemaSource, 'UNIQUE KEY `uq_anhang_filename` (`filename`)'),
    'schema provides unique filename race guard'
);
$assert(
    str_contains($attachmentSource, "hash_equals(\$filename, \$row['filename'])"),
    'case-insensitive database collation can authorize another attachment path'
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
        && str_contains(
            $controllerSource,
            'method=\\"post\\"'
        )
        && str_contains($controllerSource, '$attachmentGetActionRequested')
        && str_contains($controllerSource, 'http_response_code (405)')
        && !str_contains($controllerSource, 'isset ($_GET ["ah_upload_x"])')
        && !str_contains($controllerSource, 'isset ($_GET["ah_auswahl_x"])')
        && !str_contains(
            $controllerSource,
            'readrecord_from_db((string) ($_POST'
        )
        && str_contains($controllerSource, 'session_status () === PHP_SESSION_NONE'),
    'attachment menu actions are not scalar-safe POSTs with enforced CSRF'
);
$assert(
    str_contains($controllerSource, '$_SESSION ["anhang_menue"] ?? null')
        && str_contains($controllerSource, '$attachmentMenuState !== 100')
        && str_contains($controllerSource, '$attachmentMenuState !== 110')
        && str_contains($controllerSource, 'switch ($attachmentMenuState)')
        && !str_contains($controllerSource, 'switch ($_SESSION["anhang_menue"])'),
    'direct attachment entry normalises missing or malformed menu state'
);
$assert(
    str_contains($controllerSource, '$_SESSION ["anhang_message_context"] = true')
        && substr_count($controllerSource, '$attachmentMessageContext &&') === 2
        && str_contains(
            $controllerSource,
            'Zum Übernehmen von Anhängen öffnen Sie bitte zuerst einen Nachrichtenvordruck.'
        ),
    'attachment selection is bound to a message-form context'
);
$assert(
    str_contains($controllerSource, 'Die Anhangübersicht wurde direkt geöffnet.')
        && str_contains($controllerSource, 'anhang_menue ($attachmentContextNotice)')
        && str_contains(
            $controllerSource,
            'Hier können Sie vorhandene Anhänge ansehen oder neue Dateien hochladen.'
        ),
    'direct attachment entry renders a user-oriented standalone overview'
);
$assert(
    !str_contains($controllerSource, 'case 999:')
        && !str_contains($controllerSource, 'if ($_POST["absenden_x"])'),
    'obsolete attachment state and unguarded POST read are absent'
);
$assert(
    str_contains($controllerSource, 'preg_match ("/\\\\Alfd_[0-9]+\\\\z/D", $key)')
        && !str_contains($controllerSource, 'list($lfd, $num) = explode("_", $key)'),
    'attachment selection accepts only numeric lfd form keys'
);
$assert(
    str_contains($controllerSource, 'eStab attachment list failed:')
        && str_contains($controllerSource, 'eStab attachment reservation failed:')
        && substr_count($controllerSource, 'http_response_code (503)') >= 2
        && str_contains(
            $controllerSource,
            'Die Anhangliste kann derzeit nicht geladen werden.'
        )
        && str_contains(
            $controllerSource,
            'Der Upload kann derzeit nicht vorbereitet werden.'
        ),
    'direct list and upload preparation handle database failures'
);
$assert(
    str_contains(
        $controllerSource,
        'require_once __DIR__ . "/../app/workflow.php";'
    ),
    'attachment controller uses workflow role helpers without loading them'
);
$assert(
    preg_match(
        '/function fileselectwindow \(\)\s*\{\s*'
            . 'require \("\.\.\/4fcfg\/dbcfg\.inc\.php"\);\s*'
            . 'require \("\.\.\/4fcfg\/config\.inc\.php"\);/',
        $controllerSource
    ) === 1,
    'upload finalisation loads the complete database configuration in function scope'
);
$assert(
    str_contains($controllerSource, 'function estab_attachment_post_scalar')
        && str_contains($controllerSource, 'array_key_exists ($key, $post)')
        && str_contains($controllerSource, 'is_string ($post [$key])')
        && substr_count($controllerSource, 'estab_attachment_post_scalar ($_POST,') >= 22,
    'attachment form state safely accepts missing or non-scalar browser controls'
);
$assert(
    str_contains($controllerSource, '$distributionRequest = array ();')
        && str_contains(
            $controllerSource,
            '$distributionRequest ["16_gncopy"] = $_SESSION ["16_gncopy"];'
        )
        && str_contains(
            $controllerSource,
            '$distributionRequest [$recipientKey] = $_SESSION [$recipientKey];'
        )
        && str_contains(
            $controllerSource,
            '$data ["16_empf"] = estab_workflow_distribution_tokens ('
        )
        && str_contains($controllerSource, '$distributionRequest,')
        && str_contains($controllerSource, '$empf_matrix')
        && !str_contains(
            $controllerSource,
            '$data ["16_empf"] .= $recipientFunction'
        ),
    'attachment recipient restore is constrained to real matrix positions and copy colours'
);
$assert(
    str_contains($controllerSource, '($formdata ["10_anschrift"] ?? "") === ""')
        && str_contains($messageFormSource, 'value=\\"16_".$m.$n."_bl\\"')
        && str_contains($messageFormSource, 'if (!$this->feld[17])')
        && str_contains($messageFormSource, 'name=\\"17_vermerke\\"'),
    'returned A/W attachment form preserves address, copy selection and sighter notes'
);

$senderTaskBlock = [];
$assert(
    preg_match(
        '/\$senderAssignedByLead\s*=\s*in_array\s*\(\s*'
            . '\$this->task,\s*array\s*\((?<tasks>.*?)\),\s*true\s*\);/s',
        $messageFormSource,
        $senderTaskBlock
    ) === 1,
    'message form does not define the protected A/W incoming sender tasks'
);
$protectedSenderTasks = [];
if (isset($senderTaskBlock['tasks'])) {
    preg_match_all('/"([^"]+)"/', $senderTaskBlock['tasks'], $protectedSenderTasks);
}
$actualProtectedSenderTasks = $protectedSenderTasks[1] ?? [];
$expectedProtectedSenderTasks = [
    'FM-Eingang',
    'FM-Eingang_Anhang',
];
sort($actualProtectedSenderTasks);
sort($expectedProtectedSenderTasks);
$assert(
    $actualProtectedSenderTasks === $expectedProtectedSenderTasks,
    'message form sender protection does not cover exactly every A/W incoming task'
);

$protectedSenderRender = [];
$assert(
    preg_match(
        '/if\s*\(\$senderAssignedByLead\)\s*\{'
            . '(?<body>.*?)'
            . '\}\s*elseif\s*\(!\$this->feld\s*\[13\]\)\s*\{/s',
        $messageFormSource,
        $protectedSenderRender
    ) === 1,
    'protected A/W sender rendering branch is missing'
);
$protectedSenderBody = $protectedSenderRender['body'] ?? '';
$assert(
    str_contains($protectedSenderBody, 'data-estab-readonly=\\"true\\"')
        && str_contains($protectedSenderBody, 'Wird durch LdF aus dem Rufnamen ergänzt')
        && !str_contains($protectedSenderBody, '<input')
        && !str_contains($protectedSenderBody, 'name=\\"13_abseinheit\\"'),
    'A/W incoming form renders a writable or named sender control'
);

$assert(
    preg_match(
        '/\$attachmentIdentity\s*=\s*estab_auth_session_identity\s*\(\$_SESSION\);\s*'
            . '\$attachmentTask\s*=\s*estab_attachment_post_scalar\s*'
            . '\(\$_POST,\s*"task"\);\s*'
            . '\$incomingSenderProtected\s*=\s*'
            . 'is_array\s*\(\$attachmentIdentity\)\s*&&\s*'
            . 'estab_workflow_is_telecommunications\s*\(\$attachmentIdentity\)\s*&&\s*'
            . 'str_starts_with\s*\(\$attachmentTask,\s*"FM-Eingang"\);\s*'
            . '\$_SESSION\s*\["13_abseinheit"\]\s*=\s*'
            . '\$incomingSenderProtected\s*\?\s*""\s*:\s*'
            . 'estab_attachment_post_scalar\s*\(\$_POST,\s*"13_abseinheit"\);/s',
        $controllerSource
    ) === 1,
    'attachment storage does not discard an A/W incoming sender'
);
$assert(
    preg_match(
        '/\$restoreIdentity\s*=\s*estab_auth_session_identity\s*\(\$_SESSION\);\s*'
            . 'if\s*\(\s*is_array\s*\(\$restoreIdentity\)\s*&&\s*'
            . 'estab_workflow_is_telecommunications\s*\(\$restoreIdentity\)\s*'
            . '\)\s*\{\s*\$data\s*\["13_abseinheit"\]\s*=\s*"";\s*\}/s',
        $controllerSource
    ) === 1,
    'attachment restore can return a sender to an A/W form'
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
    str_contains($controllerSource, 'id=\\"attachment-upload-file\\"')
        && str_contains($controllerSource, 'accept=\\"".$accept."\\"')
        && str_contains($controllerSource, 'attachment-upload-help')
        && str_contains($controllerSource, 'Erlaubte Formate:')
        && str_contains($controllerSource, 'Maximale Dateigröße:')
        && str_contains($controllerSource, 'formnovalidate')
        && str_contains($controllerSource, 'user_error_message ()')
        && str_contains($controllerSource, 'estab_attachment_html ($visibleUploadFailure)'),
    'upload form does not advertise JPEG support, limit, or safe rejection reason'
);
$assert(
    !str_contains($controllerSource, 'Liste der verfÃ¼gbaren Dateien'),
    'attachment list heading contains no UTF-8 mojibake'
);
$assert(
    !str_contains($controllerSource, 'Kein Menüpunkt')
        && str_contains(
            $controllerSource,
            'Die Anhangübersicht konnte nicht initialisiert werden.'
        ),
    'attachment controller replaces the legacy dead-end fallback'
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
