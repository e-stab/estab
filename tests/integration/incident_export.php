<?php

declare(strict_types=1);

if (getenv('ESTAB_INCIDENT_EXPORT_INTEGRATION') !== '1') {
    fwrite(STDERR, "ESTAB_INCIDENT_EXPORT_INTEGRATION=1 is required\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/app/incident_export.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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

/**
 * @return array{
 *   incident_id:int,
 *   etb_id:int,
 *   ttb_id:int,
 *   message_id:int,
 *   attachment_id:int,
 *   attachment_name:string,
 *   attachment_path:string,
 *   marker:string,
 *   payload:string
 * }
 */
function incident_export_integration_empty_fixture(
    int $incidentId,
    string $marker,
    string $attachmentRoot,
    string $attachmentName,
    string $payload
): array {
    return [
        'incident_id' => $incidentId,
        'etb_id' => 0,
        'ttb_id' => 0,
        'message_id' => 0,
        'attachment_id' => 0,
        'attachment_name' => $attachmentName,
        'attachment_path' => $attachmentRoot . '/' . $attachmentName,
        'marker' => $marker,
        'payload' => $payload,
    ];
}

/** @param array<string,mixed> $fixture */
function incident_export_integration_insert_fixture(
    mysqli $connection,
    array &$fixture,
    int $messageNumber
): void {
    $incidentId = (int) $fixture['incident_id'];
    $marker = (string) $fixture['marker'];
    $attachmentName = (string) $fixture['attachment_name'];
    $attachmentPath = (string) $fixture['attachment_path'];
    $payload = (string) $fixture['payload'];
    $attachmentBase = pathinfo($attachmentName, PATHINFO_FILENAME);
    $attachmentExtension = pathinfo($attachmentName, PATHINFO_EXTENSION);
    $operatorCode = 'PDFIT';

    if (file_put_contents($attachmentPath, $payload, LOCK_EX) !== strlen($payload)) {
        throw new RuntimeException('Could not write incident export attachment');
    }
    if (!chmod($attachmentPath, 0640)) {
        throw new RuntimeException('Could not secure incident export attachment');
    }
    $attachmentDirectory = dirname($attachmentPath);
    $directoryOwner = fileowner($attachmentDirectory);
    $directoryGroup = filegroup($attachmentDirectory);
    if (
        !is_int($directoryOwner)
        || !is_int($directoryGroup)
        || (
            fileowner($attachmentPath) !== $directoryOwner
            && !chown($attachmentPath, $directoryOwner)
        )
        || (
            filegroup($attachmentPath) !== $directoryGroup
            && !chgrp($attachmentPath, $directoryGroup)
        )
    ) {
        throw new RuntimeException(
            'Could not align incident export attachment ownership'
        );
    }

    $statement = $connection->prepare(
        'INSERT INTO `nv_etb`'
            . ' (`einsatz_id`, `etb_time`, `etb_aktion`, `etb_bemerk`,'
            . ' `etb_benutzer`, `etb_kuerzel`, `etb_funktion`)'
            . ' VALUES (?, NOW(), ?, ?, ?, ?, ?)'
    );
    try {
        $action = $marker . '-ETB';
        $remark = 'ETB scope proof ' . $marker;
        $operator = 'PDF Integration';
        $function = 'S2';
        $statement->bind_param(
            'isssss',
            $incidentId,
            $action,
            $remark,
            $operator,
            $operatorCode,
            $function
        );
        $statement->execute();
        $fixture['etb_id'] = (int) $connection->insert_id;
    } finally {
        $statement->close();
    }

    $statement = $connection->prepare(
        'INSERT INTO `nv_tbb`'
            . ' (`einsatz_id`, `tbb_time`, `tbb_aktion`, `tbb_bemerk`,'
            . ' `tbb_benutzer`, `tbb_kuerzel`, `tbb_funktion`)'
            . ' VALUES (?, NOW(), ?, ?, ?, ?, ?)'
    );
    try {
        $action = $marker . '-TBB';
        $remark = 'TBB scope proof ' . $marker;
        $operator = 'PDF Integration';
        $function = 'A/W';
        $statement->bind_param(
            'isssss',
            $incidentId,
            $action,
            $remark,
            $operator,
            $operatorCode,
            $function
        );
        $statement->execute();
        $fixture['ttb_id'] = (int) $connection->insert_id;
    } finally {
        $statement->close();
    }

    $statement = $connection->prepare(
        'INSERT INTO `nv_anhang`'
            . ' (`einsatz_id`, `filename`, `fileext`, `org_filename`,'
            . ' `comment`, `md5hash`, `integrity_required`,'
            . ' `ingest_sha256`, `ingest_size`, `integrity_captured_at`,'
            . ' `date`, `kuerzel`, `status`, `id`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(6), NOW(), ?, 1, ?)'
    );
    try {
        $originalName = strtolower($marker) . '-original.txt';
        $comment = 'Attachment scope proof ' . $marker;
        $md5 = md5($payload);
        $sha256 = hash('sha256', $payload);
        $size = strlen($payload);
        $owner = 'pdf-integration-' . strtolower($marker);
        $statement->bind_param(
            'issssssiss',
            $incidentId,
            $attachmentBase,
            $attachmentExtension,
            $originalName,
            $comment,
            $md5,
            $sha256,
            $size,
            $operatorCode,
            $owner
        );
        $statement->execute();
        $fixture['attachment_id'] = (int) $connection->insert_id;
    } finally {
        $statement->close();
    }

    if (!$connection->begin_transaction()) {
        throw new RuntimeException(
            'Could not begin message evidence fixture transaction'
        );
    }
    try {
        $statement = $connection->prepare(
            'INSERT INTO `nv_nachrichten`'
                . ' (`einsatz_id`, `01_medium`, `01_datum`, `01_zeichen`,'
                . ' `04_richtung`, `04_nummer`, `05_gegenstelle`,'
                . ' `06_befweg`, `09_vorrangstufe`, `10_anschrift`,'
                . ' `12_anhang`, `12_inhalt`, `12_abfzeit`,'
                . ' `13_abseinheit`, `14_zeichen`, `14_funktion`,'
                . ' `16_empf`, `17_vermerke`, `x00_status`,'
                . ' `x01_abschluss`, `x04_druck`)'
                . " VALUES (?, 'Fu', NOW(), ?, 'E', ?, ?, ?, 'eee', ?, ?, ?,"
                . " NOW(), ?, ?, 'A/W', 'S2_rt,ALT_1_gn,', ?, 8, 't', 't')"
        );
        try {
            $remote = 'Leitstelle ' . $marker;
            $route = 'Funk ' . $marker;
            $address = 'Einsatzleitung ' . $marker;
            $attachmentReference = $attachmentName . ';';
            $content = 'Nachrichteninhalt ' . $marker;
            $sender = 'Absender ' . $marker;
            $note = 'Vermerk ' . $marker;
            $statement->bind_param(
                'isissssssss',
                $incidentId,
                $operatorCode,
                $messageNumber,
                $remote,
                $route,
                $address,
                $attachmentReference,
                $content,
                $sender,
                $operatorCode,
                $note
            );
            $statement->execute();
            $fixture['message_id'] = (int) $connection->insert_id;
        } finally {
            $statement->close();
        }

        $terminalStatement = $connection->prepare(
            'SELECT `00_lfd`, `einsatz_id`, `01_medium`, `01_datum`,'
                . ' `01_zeichen`, `02_zeit`, `02_zeichen`, `03_datum`,'
                . ' `03_zeichen`, `04_richtung`, `04_nummer`,'
                . ' `05_gegenstelle`, `06_befweg`, `06_befwegausw`,'
                . ' `07_durchspruch`, `08_befhinweis`, `08_befhinwausw`,'
                . ' `09_vorrangstufe`, `10_anschrift`, `11_gesprnotiz`,'
                . ' `12_anhang`, `12_inhalt`, `12_abfzeit`,'
                . ' `13_abseinheit`, `14_zeichen`, `14_funktion`,'
                . ' `15_quitdatum`, `15_quitzeichen`, `16_empf`,'
                . ' `17_vermerke`, `x00_status`, `x01_abschluss`,'
                . ' `estab_fernmeldeplan_eintrag_id`'
                . ' FROM `nv_nachrichten`'
                . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
        );
        try {
            $terminalMessageId = (int) $fixture['message_id'];
            $terminalStatement->bind_param(
                'ii',
                $terminalMessageId,
                $incidentId
            );
            $terminalStatement->execute();
            $terminalMessage = $terminalStatement
                ->get_result()
                ->fetch_assoc();
        } finally {
            $terminalStatement->close();
        }
        if (!is_array($terminalMessage)) {
            throw new RuntimeException('Could not reload terminal message');
        }
        estab_message_event_append(
            $connection,
            $incidentId,
            (int) $fixture['message_id'],
            'integration_completed',
            [
                'benutzer' => 'PDF Integration',
                'kuerzel' => $operatorCode,
                'funktion' => 'A/W',
            ],
            null,
            8,
            [
                'direction' => 'E',
                'terminal_message' =>
                    estab_message_terminal_snapshot($terminalMessage),
                'terminal_snapshot_sha256' =>
                    estab_message_terminal_snapshot_sha256($terminalMessage),
            ]
        );
        if (!$connection->commit()) {
            throw new RuntimeException(
                'Could not commit message evidence fixture'
            );
        }
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/** @param array<string,mixed> $fixture */
function incident_export_integration_delete_fixture(
    mysqli $connection,
    array $fixture
): void {
    $incidentId = (int) ($fixture['incident_id'] ?? 0);
    foreach ([
        ['nv_nachrichten', '00_lfd', (int) ($fixture['message_id'] ?? 0)],
        ['nv_anhang', 'lfd-nr', (int) ($fixture['attachment_id'] ?? 0)],
        ['nv_etb', 'etb_lfd-nr', (int) ($fixture['etb_id'] ?? 0)],
        ['nv_tbb', 'tbb_lfd-nr', (int) ($fixture['ttb_id'] ?? 0)],
    ] as [$table, $key, $rowId]) {
        if ($rowId < 1 || $incidentId < 1) {
            continue;
        }
        $statement = $connection->prepare(
            'DELETE FROM `' . $table . '`'
                . ' WHERE `' . $key . '` = ? AND `einsatz_id` = ?'
        );
        try {
            $statement->bind_param('ii', $rowId, $incidentId);
            $statement->execute();
        } finally {
            $statement->close();
        }
    }
    $path = (string) ($fixture['attachment_path'] ?? '');
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

function incident_export_integration_activate(
    mysqli $connection,
    int $incidentId,
    string $actor
): array {
    $status = estab_incident_status($connection);
    if ((int) ($status['active_einsatz_id'] ?? 0) === $incidentId) {
        return $status;
    }
    return estab_incident_activate(
        $connection,
        $incidentId,
        (int) $status['revision'],
        $actor
    );
}

/** @return list<string> */
function incident_export_integration_embedded_streams(string $pdf): array
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
        if ($length < 0 || $offset < 0) {
            throw new RuntimeException('Invalid embedded-file stream metadata');
        }
        $stream = substr($pdf, $offset, $length);
        if (strlen($stream) !== $length) {
            throw new RuntimeException('Truncated embedded-file stream');
        }
        $streams[] = $stream;
    }
    return $streams;
}

/** @return list<string> */
function incident_export_integration_page_streams(string $pdf): array
{
    $matched = preg_match_all(
        '/<<\/Filter \/FlateDecode \/Length ([0-9]+)>>\s+stream\r?\n/s',
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
        $compressed = substr($pdf, $offset, $length);
        if ($length < 0 || strlen($compressed) !== $length) {
            throw new RuntimeException('Truncated PDF page stream');
        }
        $decoded = gzuncompress($compressed);
        if (!is_string($decoded)) {
            throw new RuntimeException('Could not decode PDF page stream');
        }
        $streams[] = $decoded;
    }
    return $streams;
}

$password = getenv('ESTAB_DB_PASSWORD');
if (!is_string($password) || $password === '') {
    $passwordFile = getenv('ESTAB_DB_PASSWORD_FILE');
    $password = is_string($passwordFile) && is_readable($passwordFile)
        ? trim((string) file_get_contents($passwordFile))
        : '';
}
if ($password === '') {
    fwrite(STDERR, "CI database password is required\n");
    exit(2);
}

$databaseName = getenv('ESTAB_DB_NAME') ?: 'estab';
if (preg_match('/\A[A-Za-z0-9_]+\z/D', $databaseName) !== 1) {
    fwrite(STDERR, "Unsafe CI database name\n");
    exit(2);
}
$databaseConfig = [
    'server' => (getenv('ESTAB_DB_HOST') ?: 'db')
        . ':' . (getenv('ESTAB_DB_PORT') ?: '3306'),
    'user' => getenv('ESTAB_DB_USER') ?: 'estab',
    'password' => $password,
    'datenbank' => $databaseName,
];
unset($password);

$attachmentRoot = '/var/www/html/4fdata/' . $databaseName . '/anhang';
if (
    (!is_dir($attachmentRoot) && !mkdir($attachmentRoot, 0770, true))
    || !is_writable($attachmentRoot)
) {
    throw new RuntimeException('Incident export attachment root is unavailable');
}

$token = strtoupper(bin2hex(random_bytes(6)));
$actor = 'incident-export-integration';
$selectedMarker = 'PDF-SELECTED-' . $token;
$otherMarker = 'PDF-OTHER-' . $token;
$selectedName = 'PX' . $token . 'S.txt';
$otherName = 'PX' . $token . 'O.txt';
$selectedPayload = "Embedded selected incident {$token}\n"
    . "SHA-256 integrity proof\n";
$otherPayload = "Embedded other incident {$token}\n";

$connection = estab_auth_connect($databaseConfig);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$failure = null;
$originalIncidentId = 0;
$otherIncidentId = 0;
$selectedIncidentId = 0;
$otherFixture = [];
$selectedFixture = [];

try {
    $originalStatus = estab_incident_require_active($connection);
    $originalIncidentId = (int) $originalStatus['active_einsatz_id'];
    $assert(
        ($originalStatus['kennung'] ?? null) === 'CI-INTEGRATION',
        'Incident export test requires the named CI incident'
    );

    $otherCreated = estab_incident_create(
        $connection,
        [
            'kennung' => 'PDF-OTHER-' . $token,
            'name' => 'PDF-Export Fremdeinsatz ' . $token,
            'beginn' => date('Y-m-d\TH:i', time() - 120),
            'ort' => 'Andere Integrationsumgebung',
            'organisation' => 'eStab CI',
            'einsatzleitung' => 'Automatisierter Fremdtest',
            'beschreibung' =>
                'Negativkontrolle für einsatzgebundene PDF-SELECTs.',
            'metadaten' => '{"zweck":"incident-pdf-cross-scope"}',
        ],
        $actor,
        false
    );
    $otherIncidentId = (int) $otherCreated['einsatz_id'];
    incident_export_integration_activate(
        $connection,
        $otherIncidentId,
        $actor
    );
    $otherFixture = incident_export_integration_empty_fixture(
        $otherIncidentId,
        $otherMarker,
        $attachmentRoot,
        $otherName,
        $otherPayload
    );
    incident_export_integration_insert_fixture(
        $connection,
        $otherFixture,
        910001
    );

    $created = estab_incident_create(
        $connection,
        [
            'kennung' => 'PDF-' . $token,
            'name' => 'PDF-Export Integration ' . $token,
            'beginn' => date('Y-m-d\TH:i', time() - 60),
            'ort' => 'Integrationsumgebung',
            'organisation' => 'eStab CI',
            'einsatzleitung' => 'Automatisierter Test',
            'beschreibung' =>
                'Isolierter Einsatz für den MariaDB-PDF-Exportnachweis.',
            'metadaten' => '{"zweck":"incident-pdf-integration"}',
        ],
        $actor,
        false
    );
    $selectedIncidentId = (int) $created['einsatz_id'];
    $assert(
        $selectedIncidentId > 0
            && $selectedIncidentId !== $originalIncidentId,
        'Could not create an isolated PDF export incident'
    );

    incident_export_integration_activate(
        $connection,
        $selectedIncidentId,
        $actor
    );
    $selectedFixture = incident_export_integration_empty_fixture(
        $selectedIncidentId,
        $selectedMarker,
        $attachmentRoot,
        $selectedName,
        $selectedPayload
    );
    incident_export_integration_insert_fixture(
        $connection,
        $selectedFixture,
        920001
    );

    incident_export_integration_activate(
        $connection,
        $originalIncidentId,
        $actor
    );
    $statusBeforeRead = estab_incident_require_active($connection);
    $assert(
        (int) $statusBeforeRead['active_einsatz_id'] === $originalIncidentId,
        'Selected export incident was not historical before the read'
    );

    $connection->begin_transaction(
        MYSQLI_TRANS_START_READ_ONLY
            | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT
    );
    try {
        $generatedAt = new DateTimeImmutable(
            '2026-07-29 12:00:00 Europe/Berlin'
        );
        $bundle = estab_incident_export_load(
            $connection,
            $selectedIncidentId,
            [
                'etb',
                'ttb',
                'messages',
                'attachments',
                'message_evidence',
                'duty',
                's6_plans',
                'courier',
                'operations_evidence',
            ],
            $attachmentRoot
        );
        $rendered = estab_incident_export_pdf(
            $bundle,
            $actor,
            1024 * 1024,
            $generatedAt
        );
        $downloadFilename = estab_incident_export_filename(
            $bundle['incident'],
            $generatedAt
        );
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }

    $assert(
        (int) ($bundle['incident']['einsatz_id'] ?? 0)
            === $selectedIncidentId
            && ($bundle['incident']['kennung'] ?? null) === 'PDF-' . $token,
        'Dossier source resolved the wrong incident'
    );
    $assert(
        $bundle['sections'] === [
            'etb',
            'ttb',
            'messages',
            'attachments',
            'message_evidence',
            'duty',
            's6_plans',
            'courier',
            'operations_evidence',
        ],
        'Dossier source changed the selected sections'
    );
    $assert(
        $bundle['counts'] === [
            'etb' => 1,
            'ttb' => 1,
            'messages' => 1,
            'attachments' => 1,
            'attachments_verified' => 1,
            'attachments_legacy' => 0,
            'message_evidence' => 1,
            'message_evidence_heads' => 1,
            'duty' => 0,
            'duty_shifts' => 0,
            'duty_assignments' => 0,
            'duty_handovers' => 0,
            'duty_handover_requests' => 0,
            's6_plans' => 0,
            's6_plan_entries' => 0,
            'courier' => 0,
            'operations_evidence' => 0,
        ],
        'Dossier source did not return exactly the selected incident rows'
    );

    $selectedMessageId = (int) $selectedFixture['message_id'];
    $assert(
        ($bundle['etb'][0]['etb_aktion'] ?? null)
            === $selectedMarker . '-ETB'
            && ($bundle['etb'][0]['estab_event_time'] ?? null) !== null
            && ($bundle['etb'][0]['estab_recorded_at'] ?? null) !== null
            && ($bundle['etb'][0]['estab_event_type'] ?? null) === 'ereignis'
            && !str_contains(
                json_encode($bundle['etb'], JSON_THROW_ON_ERROR),
                $otherMarker
            ),
        'ETB export crossed the selected incident boundary'
    );
    $assert(
        ($bundle['ttb'][0]['tbb_aktion'] ?? null)
            === $selectedMarker . '-TBB'
            && !str_contains(
                json_encode($bundle['ttb'], JSON_THROW_ON_ERROR),
                $otherMarker
            ),
        'TBB export crossed the selected incident boundary'
    );
    $assert(
        (int) ($bundle['messages'][0]['00_lfd'] ?? 0)
            === $selectedMessageId
            && ($bundle['messages'][0]['12_inhalt'] ?? null)
                === 'Nachrichteninhalt ' . $selectedMarker
            && !str_contains(
                json_encode($bundle['messages'], JSON_THROW_ON_ERROR),
                $otherMarker
            ),
        'Message export crossed the selected incident boundary'
    );
    $assert(
        count($bundle['message_events']) === 1
            && count($bundle['message_evidence_heads']) === 1
            && (int) (
                $bundle['message_events'][0]['message_id'] ?? 0
            ) === $selectedMessageId
            && ($bundle['message_evidence_status']['valid'] ?? false) === true
            && (
                $bundle['message_evidence_status']
                    ['terminal_binding_complete'] ?? false
            ) === true
            && (
                $bundle['message_evidence_status']
                    ['terminal_mismatches'] ?? -1
            ) === 0
            && !str_contains(
                json_encode(
                    $bundle['message_events'],
                    JSON_THROW_ON_ERROR
                ),
                $otherMarker
            ),
        'Message evidence crossed the incident boundary or failed live binding'
    );
    $assert(
        $bundle['duty_shifts'] === []
            && $bundle['duty_assignments'] === []
            && $bundle['duty_handovers'] === []
            && $bundle['s6_plans'] === []
            && $bundle['s6_plan_entries'] === []
            && $bundle['courier_orders'] === []
            && $bundle['operations_events'] === []
            && (
                $bundle['operations_evidence_status']['valid'] ?? false
            ) === true
            && (
                $bundle['operations_evidence_status']
                    ['stored_head_sha256'] ?? null
            ) === str_repeat('0', 64),
        'Empty DV evidence sections were not represented explicitly'
    );
    $assert(
        ($bundle['attachment_names_by_message'][$selectedMessageId] ?? null)
            === [$selectedName],
        'Message-to-attachment relation was not scoped to the selected incident'
    );
    $matrix = $bundle['recipient_matrix'] ?? null;
    $assert(
        is_array($matrix)
            && array_sum(array_map('count', $matrix)) === 20,
        'Message-form recipient matrix is missing or incomplete'
    );
    $assert(
        ($bundle['attachments'][0]['stored_name'] ?? null) === $selectedName
            && ($bundle['attachments'][0]['integrity_state'] ?? null)
                === 'verified'
            && ($bundle['attachments'][0]['message_ids'] ?? null)
                === [$selectedMessageId]
            && hash_file(
                'sha256',
                (string) $bundle['attachments'][0]['path']
            ) === hash('sha256', $selectedPayload)
            && !str_contains(
                json_encode($bundle['attachments'], JSON_THROW_ON_ERROR),
                $otherName
            ),
        'Attachment export crossed the incident boundary or changed bytes'
    );

    $pdf = $rendered['bytes'];
    $assert(
        str_starts_with($pdf, '%PDF-1.7')
            && str_ends_with($pdf, "%%EOF\n")
            && str_contains($pdf, '/EmbeddedFiles')
            && str_contains($pdf, '/Type /Filespec')
            && str_contains($pdf, '/AFRelationship /Data'),
        'Rendered incident dossier lacks its PDF/EmbeddedFile contract'
    );
    $assert(
        $rendered['attachment_count'] === 1
            && $rendered['attachment_bytes'] === strlen($selectedPayload),
        'Rendered incident dossier reports wrong attachment totals'
    );
    $assert(
        $rendered['sha256'] === hash('sha256', $pdf),
        'Rendered incident dossier SHA-256 does not cover the returned bytes'
    );
    $embeddedStreams = incident_export_integration_embedded_streams($pdf);
    $assert(
        $embeddedStreams === [$selectedPayload],
        'Extracted EmbeddedFile is not byte-identical to the selected original'
    );
    $assert(
        hash('sha256', $embeddedStreams[0])
            === hash('sha256', $selectedPayload)
            && !str_contains($pdf, $otherPayload),
        'EmbeddedFile integrity or cross-incident exclusion failed'
    );
    $pageContent = implode(
        "\n",
        incident_export_integration_page_streams($pdf)
    );
    $assert(
        str_contains($pageContent, $selectedMarker . '-ETB')
            && str_contains($pageContent, $selectedMarker . '-TBB')
            && str_contains(
                $pageContent,
                'Nachrichteninhalt ' . $selectedMarker
            )
            && str_contains($pageContent, 'EINGANG')
            && str_contains($pageContent, 'AUSGANG')
            && str_contains($pageContent, 'Nachweis-Nr.')
            && str_contains($pageContent, 'Fm-Betriebsstelle')
            && str_contains($pageContent, 'ALT_1 [gn]')
            && str_contains(
                $pageContent,
                'VORL'
            )
            && str_contains(
                $pageContent,
                'Nachrichtenereignisse und Nachweisk'
            )
            && str_contains($pageContent, 'Dienstorganisation')
            && str_contains($pageContent, 'S6-Fernmeldeplanung')
            && str_contains($pageContent, 'Melderauftr')
            && str_contains(
                $pageContent,
                'Betriebsereignisse und Nachweiskopf'
            )
            && str_contains(
                $pageContent,
                hash('sha256', $selectedPayload)
            )
            && !str_contains($pageContent, 'Dienstgebrauch')
            && !str_contains($pageContent, $otherMarker),
        'Rendered pages omit the shared form, attachment SHA-256, or incident scope'
    );
    $assert(
        !str_contains($pdf, '/Subtype /Image'),
        'Rendered incident dossier still contains the coat of arms'
    );
    $tamperedPayload = str_repeat('X', strlen($selectedPayload));
    if ($tamperedPayload === $selectedPayload) {
        $tamperedPayload[0] = 'Y';
    }
    try {
        if (
            file_put_contents(
                (string) $selectedFixture['attachment_path'],
                $tamperedPayload,
                LOCK_EX
            ) !== strlen($tamperedPayload)
        ) {
            throw new RuntimeException('Could not write tamper fixture');
        }
        $loadRejected = false;
        try {
            estab_incident_export_load(
                $connection,
                $selectedIncidentId,
                $bundle['sections'],
                $attachmentRoot
            );
        } catch (EstabIncidentExportDataException) {
            $loadRejected = true;
        }
        $assert(
            $loadRejected,
            'Changed attachment was accepted while loading an export'
        );

        $renderRejected = false;
        try {
            estab_incident_export_pdf(
                $bundle,
                $actor,
                1024 * 1024,
                $generatedAt
            );
        } catch (EstabIncidentPdfInputException) {
            $renderRejected = true;
        }
        $assert(
            $renderRejected,
            'Attachment changed after loading was embedded as ingest-original'
        );

        $tamperedPreflight = estab_incident_close_preflight(
            $connection,
            $selectedIncidentId,
            $attachmentRoot
        );
        $assert(
            ($tamperedPreflight['attachment_integrity_errors'] ?? 0) === 1
                && ($tamperedPreflight['closable'] ?? true) === false,
            'Formal-close preflight accepted a changed attachment'
        );

        $selectedStatus = incident_export_integration_activate(
            $connection,
            $selectedIncidentId,
            $actor
        );
        $closeRejected = null;
        try {
            estab_incident_close(
                $connection,
                $selectedIncidentId,
                (int) ($selectedStatus['revision'] ?? -1),
                $actor,
                [
                    'ende' => date('Y-m-d\TH:i'),
                    'close_note' =>
                        'Tamper-Negativkontrolle; darf nie gespeichert werden.',
                ],
                $attachmentRoot
            );
        } catch (EstabIncidentCloseBlockedException $exception) {
            $closeRejected = $exception;
        }
        $assert(
            $closeRejected instanceof EstabIncidentCloseBlockedException
                && (
                    $closeRejected->preflight
                        ['attachment_integrity_errors'] ?? 0
                ) === 1
                && (
                    $closeRejected->preflight['closable'] ?? true
                ) === false,
            'Formal close did not reject a changed attachment'
        );
        incident_export_integration_activate(
            $connection,
            $originalIncidentId,
            $actor
        );
    } finally {
        file_put_contents(
            (string) $selectedFixture['attachment_path'],
            $selectedPayload,
            LOCK_EX
        );
    }
    $assert(
        $downloadFilename === 'estab-einsatz-' . $selectedIncidentId
            . '-pdf-' . strtolower($token) . '-20260729-120000.pdf',
        'Historical dossier filename is not bound to incident ID and identifier'
    );
} catch (Throwable $exception) {
    $failure = $exception;
}

try {
    if ($originalIncidentId > 0) {
        incident_export_integration_activate(
            $connection,
            $originalIncidentId,
            $actor
        );
    }
} catch (Throwable $cleanupException) {
    if (!$failure instanceof Throwable) {
        $failure = $cleanupException;
    } else {
        fwrite(
            STDERR,
            'Incident export fixture cleanup also failed: '
                . $cleanupException->getMessage()
                . "\n"
        );
    }
} finally {
    estab_auth_close($connection);
}

if ($failure instanceof Throwable) {
    throw $failure;
}

echo "incident export integration: OK ({$assertions} assertions, "
    . "historical incident {$selectedIncidentId}, cross-scope incident "
    . "{$otherIncidentId}, retained evidence, byte-identical EmbeddedFile)\n";
