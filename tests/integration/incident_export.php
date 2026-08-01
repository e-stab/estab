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
 *   shift_id:int,
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
        'shift_id' => 0,
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

function incident_export_integration_create_shift(
    mysqli $connection,
    int $incidentId,
    int $number,
    string $label,
    string $actor
): int {
    $statement = $connection->prepare(
        'INSERT INTO `nv_dienstschichten`'
            . ' (`einsatz_id`, `nummer`, `bezeichnung`, `status`, `erstellt_von`)'
            . " VALUES (?, ?, ?, 'GEPLANT', ?)"
    );
    try {
        $statement->bind_param('iiss', $incidentId, $number, $label, $actor);
        $statement->execute();
        return (int) $connection->insert_id;
    } finally {
        $statement->close();
    }
}

/** @return array{etb_id:int,ttb_id:int} */
function incident_export_integration_insert_logbook_pair(
    mysqli $connection,
    int $incidentId,
    int $shiftId,
    string $marker
): array {
    $statement = $connection->prepare(
        'INSERT INTO `nv_etb`'
            . ' (`einsatz_id`, `estab_shift_id`, `etb_time`, `etb_aktion`,'
            . ' `etb_bemerk`, `etb_benutzer`, `etb_kuerzel`, `etb_funktion`,'
            . ' `estab_event_time`, `estab_event_type`)'
            . " VALUES (?, ?, NOW(), ?, ?, 'eStab-System', 'system',"
            . " 'System', NOW(6), 'ohne')"
    );
    try {
        $action = $marker . '-ETB';
        $remark = 'ETB scope proof ' . $marker;
        $statement->bind_param(
            'iiss',
            $incidentId,
            $shiftId,
            $action,
            $remark
        );
        $statement->execute();
        $etbId = (int) $connection->insert_id;
    } finally {
        $statement->close();
    }

    $statement = $connection->prepare(
        'INSERT INTO `nv_tbb`'
            . ' (`einsatz_id`, `estab_shift_id`, `tbb_time`, `tbb_aktion`,'
            . ' `tbb_bemerk`, `tbb_benutzer`, `tbb_kuerzel`, `tbb_funktion`,'
            . ' `estab_event_time`, `estab_entry_type`, `estab_operations`)'
            . " VALUES (?, ?, NOW(), ?, ?, 'eStab-System', 'system',"
            . " 'System', NOW(6), 'betriebsereignis', ?)"
    );
    try {
        $action = $marker . '-TBB';
        $remark = 'TBB scope proof ' . $marker;
        $statement->bind_param(
            'iisss',
            $incidentId,
            $shiftId,
            $action,
            $remark,
            $action
        );
        $statement->execute();
        $ttbId = (int) $connection->insert_id;
    } finally {
        $statement->close();
    }

    return ['etb_id' => $etbId, 'ttb_id' => $ttbId];
}

/** @return array{etb_id:int,ttb_id:int} */
function incident_export_integration_insert_logbook_corrections(
    mysqli $connection,
    int $incidentId,
    int $shiftId,
    string $marker,
    int $originalEtbId,
    int $originalEtbBookLfd,
    int $originalTtbId
): array {
    $statement = $connection->prepare(
        'INSERT INTO `nv_etb`'
            . ' (`einsatz_id`, `estab_shift_id`, `etb_time`, `etb_aktion`,'
            . ' `etb_bemerk`, `etb_benutzer`, `etb_kuerzel`, `etb_funktion`,'
            . ' `estab_event_time`, `estab_event_type`,'
            . ' `estab_reference`, `estab_correction_of`)'
            . " VALUES (?, ?, NOW(), ?, ?, 'eStab-System', 'system',"
            . " 'System', NOW(6), 'korrektur', ?, ?)"
    );
    try {
        $action = $marker . '-ETB';
        $remark = 'Schichtübergreifende ETB-Korrektur ' . $marker;
        $reference = (string) $originalEtbBookLfd;
        $statement->bind_param(
            'iisssi',
            $incidentId,
            $shiftId,
            $action,
            $remark,
            $reference,
            $originalEtbId
        );
        $statement->execute();
        $etbId = (int) $connection->insert_id;
    } finally {
        $statement->close();
    }

    $statement = $connection->prepare(
        'INSERT INTO `nv_tbb`'
            . ' (`einsatz_id`, `estab_shift_id`, `tbb_time`, `tbb_aktion`,'
            . ' `tbb_bemerk`, `tbb_benutzer`, `tbb_kuerzel`, `tbb_funktion`,'
            . ' `estab_event_time`, `estab_entry_type`, `estab_operations`,'
            . ' `estab_correction_of`)'
            . " VALUES (?, ?, NOW(), ?, ?, 'eStab-System', 'system',"
            . " 'System', NOW(6), 'korrektur', ?, ?)"
    );
    try {
        $action = $marker . '-TBB';
        $remark = 'Schichtübergreifende TBB-Korrektur ' . $marker;
        $statement->bind_param(
            'iisssi',
            $incidentId,
            $shiftId,
            $action,
            $remark,
            $action,
            $originalTtbId
        );
        $statement->execute();
        $ttbId = (int) $connection->insert_id;
    } finally {
        $statement->close();
    }

    return ['etb_id' => $etbId, 'ttb_id' => $ttbId];
}

/** @param array<string,mixed> $fixture */
function incident_export_integration_insert_fixture(
    mysqli $connection,
    array &$fixture,
    int $messageNumber
): void {
    $incidentId = (int) $fixture['incident_id'];
    $shiftId = (int) $fixture['shift_id'];
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

    if ($shiftId < 1) {
        throw new RuntimeException('Incident export fixture has no duty shift');
    }
    $logbookRows = incident_export_integration_insert_logbook_pair(
        $connection,
        $incidentId,
        $shiftId,
        $marker
    );
    $fixture['etb_id'] = $logbookRows['etb_id'];
    $fixture['ttb_id'] = $logbookRows['ttb_id'];

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
                . ' `11_rufnummer`, `12_anhang`, `12_betreff`,'
                . ' `12_inhalt`, `12_abfzeit`,'
                . ' `13_abseinheit`, `14_zeichen`, `14_funktion`,'
                . ' `16_empf`, `17_vermerke`, `x00_status`,'
                . ' `x01_abschluss`, `x04_druck`)'
                . " VALUES (?, 'Fu', NOW(), ?, 'E', ?, ?, ?, 'eee', ?, ?, ?,"
                . " ?, ?, NOW(), ?, ?, 'A/W', 'S2_rt,ALT_1_gn,', ?,"
                . " 8, 't', 't')"
        );
        try {
            $remote = 'Leitstelle ' . $marker;
            $route = 'Funk ' . $marker;
            $address = 'Einsatzleitung ' . $marker;
            $phone = '0711-' . $marker;
            $attachmentReference = $attachmentName . ';';
            $subject = 'Lagebetreff ' . $marker;
            $content = 'Nachrichteninhalt ' . $marker;
            $sender = 'Absender ' . $marker;
            $note = 'Vermerk ' . $marker;
            $statement->bind_param(
                'isissssssssss',
                $incidentId,
                $operatorCode,
                $messageNumber,
                $remote,
                $route,
                $address,
                $phone,
                $attachmentReference,
                $subject,
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
                . ' `09_vorrangstufe`, `10_anschrift`, `11_rufnummer`,'
                . ' `11_gesprnotiz`, `12_anhang`, `12_betreff`,'
                . ' `12_inhalt`, `12_abfzeit`,'
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

/** Build a deterministic two-page PDF used as a real visible attachment. */
function incident_export_integration_pdf_attachment(string $marker): string
{
    $source = new FPDF('P', 'mm', 'A4');
    $source->SetCompression(false);
    foreach (
        [
            [220, 235, 250, 'PDF-QUELLSEITE-1'],
            [250, 230, 210, 'PDF-QUELLSEITE-2'],
        ] as [$red, $green, $blue, $pageMarker]
    ) {
        $source->AddPage();
        $source->SetFillColor($red, $green, $blue);
        $source->Rect(10, 10, 190, 277, 'F');
        $source->SetTextColor(23, 47, 77);
        $source->SetFont('helvetica', 'B', 24);
        $source->SetXY(20, 120);
        $source->Cell(170, 15, $pageMarker, 0, 1, 'C');
        $source->SetFont('helvetica', '', 10);
        $source->SetX(20);
        $source->Cell(170, 8, $marker, 0, 1, 'C');
    }
    return $source->Output('', 'S');
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
$selectedCommandPost = 'COMMAND-POST-SELECTED-' . $token;
$otherCommandPost = 'COMMAND-POST-OTHER-' . $token;
$selectedName = 'PX' . $token . 'S.pdf';
$otherName = 'PX' . $token . 'O.txt';
$selectedPayload = incident_export_integration_pdf_attachment(
    'Embedded selected incident ' . $token
);
$otherPayload = "Embedded other incident {$token}\n";

$connection = estab_auth_connect($databaseConfig);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$failure = null;
$originalIncidentId = 0;
$otherIncidentId = 0;
$selectedIncidentId = 0;
$otherShiftId = 0;
$selectedShiftId = 0;
$selectedSecondShiftId = 0;
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
            'fuehrungsstellenname' => $otherCommandPost,
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
    $otherShiftId = incident_export_integration_create_shift(
        $connection,
        $otherIncidentId,
        1,
        'Fremdschicht ' . $token,
        $actor
    );
    $otherFixture['shift_id'] = $otherShiftId;
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
            'fuehrungsstellenname' => $selectedCommandPost,
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
    $selectedShiftId = incident_export_integration_create_shift(
        $connection,
        $selectedIncidentId,
        1,
        'Tagschicht ' . $token,
        $actor
    );
    $selectedSecondShiftId = incident_export_integration_create_shift(
        $connection,
        $selectedIncidentId,
        2,
        'Nachtschicht ' . $token,
        $actor
    );
    $selectedFixture['shift_id'] = $selectedShiftId;
    incident_export_integration_insert_fixture(
        $connection,
        $selectedFixture,
        920001
    );
    $secondShiftMarker = $selectedMarker . '-SHIFT-2';
    incident_export_integration_insert_logbook_corrections(
        $connection,
        $selectedIncidentId,
        $selectedSecondShiftId,
        $secondShiftMarker,
        (int) $selectedFixture['etb_id'],
        1,
        (int) $selectedFixture['ttb_id']
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
            && ($bundle['incident']['kennung'] ?? null) === 'PDF-' . $token
            && ($bundle['incident']['fuehrungsstellenname'] ?? null)
                === $selectedCommandPost
            && !str_contains(
                json_encode($bundle['incident'], JSON_THROW_ON_ERROR),
                $otherCommandPost
            ),
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
        ($bundle['logbook_scope']['mode'] ?? null) === 'all'
            && array_key_exists('shift_id', $bundle['logbook_scope'])
            && $bundle['logbook_scope']['shift_id'] === null
            && str_contains(
                (string) ($bundle['logbook_scope']['display_label'] ?? ''),
                'Gesamtbuch'
            ),
        'Default dossier scope is not the complete ETB/TBB book'
    );
    $assert(
        $bundle['counts'] === [
            'etb' => 2,
            'ttb' => 2,
            'messages' => 1,
            'attachments' => 1,
            'attachments_verified' => 1,
            'attachments_legacy' => 0,
            'message_evidence' => 1,
            'message_evidence_heads' => 1,
            'duty' => 2,
            'duty_shifts' => 2,
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
            && (int) ($bundle['etb'][0]['estab_shift_id'] ?? 0)
                === $selectedShiftId
            && ($bundle['etb'][0]['estab_assignment'] ?? null) === null
            && ($bundle['etb'][1]['etb_aktion'] ?? null)
                === $secondShiftMarker . '-ETB'
            && (int) ($bundle['etb'][1]['estab_shift_id'] ?? 0)
                === $selectedSecondShiftId
            && (int) (
                $bundle['etb'][1]['estab_correction_of'] ?? 0
            ) === (int) $selectedFixture['etb_id']
            && (int) (
                $bundle['etb'][1]['estab_correction_book_lfd'] ?? 0
            ) === 1
            && ($bundle['etb'][0]['estab_event_time'] ?? null) !== null
            && ($bundle['etb'][0]['estab_recorded_at'] ?? null) !== null
            && ($bundle['etb'][0]['estab_event_type'] ?? null) === 'ohne'
            && !str_contains(
                json_encode($bundle['etb'], JSON_THROW_ON_ERROR),
                $otherMarker
            ),
        'ETB export crossed the selected incident boundary'
    );
    $assert(
        ($bundle['ttb'][0]['tbb_aktion'] ?? null)
            === $selectedMarker . '-TBB'
            && (int) ($bundle['ttb'][0]['estab_shift_id'] ?? 0)
                === $selectedShiftId
            && ($bundle['ttb'][1]['tbb_aktion'] ?? null)
                === $secondShiftMarker . '-TBB'
            && (int) ($bundle['ttb'][1]['estab_shift_id'] ?? 0)
                === $selectedSecondShiftId
            && (int) (
                $bundle['ttb'][1]['estab_correction_of'] ?? 0
            ) === (int) $selectedFixture['ttb_id']
            && (int) (
                $bundle['ttb'][1]['estab_correction_book_lfd'] ?? 0
            ) === 1
            && !str_contains(
                json_encode($bundle['ttb'], JSON_THROW_ON_ERROR),
                $otherMarker
            ),
        'TBB export crossed the selected incident boundary'
    );

    $filteredBundle = estab_incident_export_load(
        $connection,
        $selectedIncidentId,
        $bundle['sections'],
        $attachmentRoot,
        'shift:' . $selectedShiftId
    );
    $assert(
        ($filteredBundle['logbook_scope']['mode'] ?? null) === 'shift'
            && (int) (
                $filteredBundle['logbook_scope']['shift_id'] ?? 0
            ) === $selectedShiftId
            && (int) ($filteredBundle['logbook_scope']['number'] ?? 0) === 1
            && ($filteredBundle['logbook_scope']['name'] ?? null)
                === 'Tagschicht ' . $token
            && ($filteredBundle['logbook_scope']['status'] ?? null)
                === 'GEPLANT'
            && array_key_exists(
                'created_at',
                $filteredBundle['logbook_scope']
            )
            && array_key_exists(
                'activated_at',
                $filteredBundle['logbook_scope']
            )
            && array_key_exists(
                'ended_at',
                $filteredBundle['logbook_scope']
            ),
        'Selected shift metadata is incomplete or belongs to the wrong shift'
    );
    $assert(
        ($filteredBundle['counts']['etb'] ?? -1) === 1
            && ($filteredBundle['counts']['ttb'] ?? -1) === 1
            && ($filteredBundle['counts']['messages'] ?? -1) === 1
            && ($filteredBundle['counts']['attachments'] ?? -1) === 1
            && ($filteredBundle['counts']['duty_shifts'] ?? -1) === 2
            && ($filteredBundle['etb'][0]['etb_aktion'] ?? null)
                === $selectedMarker . '-ETB'
            && ($filteredBundle['ttb'][0]['tbb_aktion'] ?? null)
                === $selectedMarker . '-TBB'
            && !str_contains(
                json_encode([
                    $filteredBundle['etb'],
                    $filteredBundle['ttb'],
                ], JSON_THROW_ON_ERROR),
                $secondShiftMarker
            ),
        'Shift scope did not filter only ETB/TBB while retaining dossier data'
    );
    $filteredRendered = estab_incident_export_pdf(
        $filteredBundle,
        $actor,
        1024 * 1024,
        $generatedAt
    );
    $filteredPageText = implode(
        "\n",
        incident_export_integration_page_streams($filteredRendered['bytes'])
    );
    $assert(
        str_contains(
            $filteredPageText,
            'Nur Dienstschicht 1'
        )
            && str_contains($filteredPageText, 'Tagschicht ' . $token)
            && str_contains($filteredPageText, $selectedMarker . '-ETB')
            && str_contains($filteredPageText, $selectedMarker . '-TBB')
            && !str_contains($filteredPageText, $secondShiftMarker),
        'Filtered PDF cover or logbook pages do not match the selected shift'
    );

    $correctionBundle = estab_incident_export_load(
        $connection,
        $selectedIncidentId,
        ['etb', 'ttb'],
        $attachmentRoot,
        'shift:' . $selectedSecondShiftId
    );
    $assert(
        ($correctionBundle['counts']['etb'] ?? -1) === 1
            && ($correctionBundle['counts']['ttb'] ?? -1) === 1
            && (int) (
                $correctionBundle['etb'][0]
                    ['estab_correction_book_lfd'] ?? 0
            ) === 1
            && (int) (
                $correctionBundle['ttb'][0]
                    ['estab_correction_book_lfd'] ?? 0
            ) === 1
            && (int) (
                $correctionBundle['etb'][0]['estab_correction_of'] ?? 0
            ) === (int) $selectedFixture['etb_id']
            && (int) (
                $correctionBundle['ttb'][0]['estab_correction_of'] ?? 0
            ) === (int) $selectedFixture['ttb_id'],
        'Cross-shift correction did not resolve the original local book number'
    );
    $correctionRendered = estab_incident_export_pdf(
        $correctionBundle,
        $actor,
        1024 * 1024,
        $generatedAt
    );
    $correctionPageText = implode(
        "\n",
        incident_export_integration_page_streams(
            $correctionRendered['bytes']
        )
    );
    $assert(
        str_contains($correctionPageText, 'Korrektur zu ETB-Nr.: 1')
            && str_contains($correctionPageText, 'Korrektur zu TBB-Nr.: 1')
            && !str_contains(
                $correctionPageText,
                'Korrektur zu ETB-Nr.: '
                    . (string) $selectedFixture['etb_id']
            )
            && !str_contains(
                $correctionPageText,
                'Korrektur zu TBB-Nr.: '
                    . (string) $selectedFixture['ttb_id']
            ),
        'Filtered PDF labels a global primary key as a local book number'
    );

    $foreignShiftRejected = false;
    try {
        estab_incident_export_load(
            $connection,
            $selectedIncidentId,
            ['etb', 'ttb'],
            $attachmentRoot,
            'shift:' . $otherShiftId
        );
    } catch (EstabIncidentExportInputException $exception) {
        $foreignShiftRejected = str_contains(
            $exception->getMessage(),
            'gehört nicht zum ausgewählten Einsatz'
        );
    }
    $assert(
        $foreignShiftRejected,
        'A foreign incident shift was accepted as an ETB/TBB filter'
    );
    $assert(
        (int) ($bundle['messages'][0]['00_lfd'] ?? 0)
            === $selectedMessageId
            && ($bundle['messages'][0]['11_rufnummer'] ?? null)
                === '0711-' . $selectedMarker
            && ($bundle['messages'][0]['12_betreff'] ?? null)
                === 'Lagebetreff ' . $selectedMarker
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
        count($bundle['duty_shifts']) === 2
            && (int) (
                $bundle['duty_shifts'][0]['dienstschicht_id'] ?? 0
            ) === $selectedShiftId
            && (int) (
                $bundle['duty_shifts'][1]['dienstschicht_id'] ?? 0
            ) === $selectedSecondShiftId
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
            && $rendered['attachment_bytes'] === strlen($selectedPayload)
            && $rendered['attachment_visible_count'] === 1
            && $rendered['attachment_visible_pages'] === 2
            && $rendered['attachment_rendered_count'] === 1
            && $rendered['attachment_rendered_pages'] === 2
            && $rendered['attachment_information_pages'] === 0,
        'Rendered incident dossier reports wrong attachment or visible-page totals'
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
        str_contains($pageContent, $selectedCommandPost)
            && str_contains($pageContent, 'Gesamtbuch')
            && str_contains($pageContent, $selectedMarker . '-ETB')
            && str_contains($pageContent, $selectedMarker . '-TBB')
            && str_contains($pageContent, $secondShiftMarker . '-ETB')
            && str_contains($pageContent, $secondShiftMarker . '-TBB')
            && str_contains(
                $pageContent,
                'Nachrichteninhalt ' . $selectedMarker
            )
            && str_contains($pageContent, '0711-' . $selectedMarker)
            && str_contains(
                $pageContent,
                'Lagebetreff ' . $selectedMarker
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
            && str_contains($pageContent, 'Anlage 1 von 1')
            && str_contains($pageContent, 'Originalseite 1 von 2')
            && str_contains($pageContent, 'Originalseite 2 von 2')
            && str_contains($pageContent, 'Sichtbare Darstellung')
            && !str_contains($pageContent, 'Dienstgebrauch')
            && !str_contains($pageContent, $otherMarker)
            && !str_contains($pageContent, $otherCommandPost),
        'Rendered pages omit the shared form, attachment SHA-256, or incident scope'
    );
    preg_match_all(
        '/\/Subtype \/Image\s+\/Width ([0-9]+)\s+\/Height ([0-9]+)/',
        $pdf,
        $imageObjects,
        PREG_SET_ORDER
    );
    $visibleAttachmentImages = array_values(array_filter(
        $imageObjects,
        static function (array $image): bool {
            return (int) ($image[1] ?? 0) !== 400
                || (int) ($image[2] ?? 0) !== 396;
        }
    ));
    $assert(
        count($visibleAttachmentImages) === 2
            && array_reduce(
                $visibleAttachmentImages,
                static fn (bool $valid, array $image): bool => $valid
                    && (int) ($image[1] ?? 0) >= 500
                    && (int) ($image[1] ?? 0)
                        <= ESTAB_INCIDENT_PDF_RENDER_AXIS
                    && (int) ($image[2] ?? 0) >= 500
                    && (int) ($image[2] ?? 0)
                        <= ESTAB_INCIDENT_PDF_RENDER_AXIS,
                true
            ),
        'Both source PDF pages were not embedded as bounded visible images'
    );
    $assert(
        str_contains($pdf, '/Subtype /Image')
            && str_contains($pdf, '/Width 400')
            && str_contains($pdf, '/Height 396')
            && str_contains($pdf, '/BitsPerComponent 1'),
        'Rendered incident dossier lacks the permitted THW header mark'
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
