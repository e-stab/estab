<?php

declare(strict_types=1);

if (getenv('ESTAB_DV_EVIDENCE_INTEGRATION') !== '1') {
    fwrite(STDERR, "ESTAB_DV_EVIDENCE_INTEGRATION=1 is required\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/app/incident.php';
require_once dirname(__DIR__, 2) . '/app/message_evidence.php';
require_once dirname(__DIR__, 2) . '/app/logbook.php';

$databaseName = getenv('ESTAB_DB_NAME') ?: '';
if (preg_match('/\Aestab_dv_evidence_[a-z0-9_]*\z/D', $databaseName) !== 1) {
    fwrite(STDERR, "Refusing to run DV evidence integration outside an isolated database\n");
    exit(2);
}
$password = getenv('ESTAB_DB_PASSWORD');
if (!is_string($password) || $password === '') {
    $passwordFile = getenv('ESTAB_DB_PASSWORD_FILE');
    $password = is_string($passwordFile) && is_readable($passwordFile)
        ? trim((string) file_get_contents($passwordFile))
        : '';
}
if ($password === '') {
    fwrite(STDERR, "DV evidence integration database password is required\n");
    exit(2);
}
$databaseConfig = [
    'server' => getenv('ESTAB_DB_HOST') ?: 'db',
    'user' => getenv('ESTAB_DB_USER') ?: 'estab',
    'password' => $password,
    'datenbank' => $databaseName,
];
unset($password);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = estab_auth_connect($databaseConfig);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$fails = static function (callable $operation): ?Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    return null;
};
$scalar = static function (mysqli $connection, string $sql): mixed {
    $result = $connection->query($sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Evidence integration query returned no result');
    }
    try {
        $row = $result->fetch_row();
    } finally {
        $result->free();
    }
    return is_array($row) ? ($row[0] ?? null) : null;
};

$actor = [
    'benutzer' => 'Evidence Integration',
    'kuerzel' => 'evi',
    'funktion' => 'S2',
    'rolle' => 'Stab',
];

try {
    $status = estab_incident_status($connection);
    $assert(
        $status['active_einsatz_id'] === null,
        'isolated evidence database unexpectedly has an active incident'
    );
    $foreignCreated = estab_incident_create(
        $connection,
        [
            'kennung' => 'DV-EVIDENCE-FOREIGN',
            'name' => 'Fremder Referenzeinsatz',
            'beginn' => date('Y-m-d\TH:i', time() - 7200),
            'ort' => 'Integration',
            'fuehrungsstellenname' => 'Führungsstelle Evidenz Fremd',
        ],
        'evidence-integration',
        true,
        (int) $status['revision']
    );
    $foreignIncidentId = (int) $foreignCreated['einsatz_id'];
    $connection->begin_transaction();
    try {
        $statement = $connection->prepare(
            'INSERT INTO `nv_nachrichten`'
            . ' (`einsatz_id`, `12_inhalt`, `x00_status`, `x01_abschluss`)'
            . " VALUES (?, ?, 8, 't')"
        );
        $foreignBody = 'Nachricht eines anderen Einsatzes';
        $statement->bind_param('is', $foreignIncidentId, $foreignBody);
        $statement->execute();
        $foreignMessageId = (int) $connection->insert_id;
        $statement->close();
        estab_message_event_append(
            $connection,
            $foreignIncidentId,
            $foreignMessageId,
            'angelegt',
            $actor,
            null,
            8,
            ['12_inhalt' => $foreignBody, 'x00_status' => 8]
        );
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    $connection->query(
        "INSERT INTO `nv_anhang` (`filename`, `status`,"
        . " `integrity_required`, `ingest_sha256`, `ingest_size`,"
        . " `integrity_captured_at`)"
        . " VALUES ('FOREIGN001', 1, 1,"
        . " SHA2('foreign-attachment', 256),"
        . " LENGTH('foreign-attachment'), NOW(6))"
    );
    $foreignAttachmentId = (int) $connection->insert_id;
    $foreignEventTime = date('Y-m-d H:i:s', time() - 180);
    $foreignEtb = $connection->prepare(
        'INSERT INTO `nv_etb`'
        . ' (`einsatz_id`, `etb_time`, `etb_aktion`, `etb_bemerk`,'
        . ' `etb_funktion`, `etb_kuerzel`, `etb_benutzer`,'
        . ' `estab_event_time`, `estab_event_type`)'
        . " VALUES (?, ?, 'ETB-Eintrag eines anderen Einsatzes', '',"
        . " 'S2', 'evi', 'Evidence Integration', ?, 'information')"
    );
    $foreignEtb->bind_param(
        'iss',
        $foreignIncidentId,
        $foreignEventTime,
        $foreignEventTime
    );
    $foreignEtb->execute();
    $foreignEntryId = (int) $connection->insert_id;
    $foreignEtb->close();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $status = estab_incident_status($connection);
    $created = estab_incident_create(
        $connection,
        [
            'kennung' => 'DV-EVIDENCE-001',
            'name' => 'DV Evidenztest',
            'beginn' => date('Y-m-d\TH:i', time() - 3600),
            'ort' => 'Integration',
            'fuehrungsstellenname' => 'Führungsstelle Evidenz',
        ],
        'evidence-integration',
        true,
        (int) $status['revision']
    );
    $incidentId = (int) $created['einsatz_id'];
    $revision = (int) $created['status_revision'];
    $evidencePassword = password_hash(
        'DV evidence integration',
        PASSWORD_DEFAULT
    );
    $evidenceSession = 'dv-evidence-session';
    $insertEvidenceUser = $connection->prepare(
        'INSERT INTO `nv_benutzer`'
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `aktiv`,'
        . ' `estab_letzte_aktivitaet`, `estab_gesperrt`, `password`)'
        . " VALUES ('Evidence Integration', 'evi', 'S2', 'Stab', ?, 1,"
        . ' UTC_TIMESTAMP(6), 0, ?)'
    );
    $insertEvidenceUser->bind_param(
        'ss',
        $evidenceSession,
        $evidencePassword
    );
    $insertEvidenceUser->execute();
    $insertEvidenceUser->close();
    $evidenceShift = estab_dv_create_shift(
        $connection,
        $incidentId,
        'DV-Evidenzschicht',
        null,
        'evidence-integration'
    );
    $evidenceShiftId = (int) $evidenceShift['dienstschicht_id'];
    $evidenceAssignmentId = 0;
    foreach (ESTAB_DV_REQUIRED_HATS as $function) {
        $assignment = estab_dv_assign_hat(
            $connection,
            $incidentId,
            $evidenceShiftId,
            'evi',
            $function,
            'evidence-integration'
        );
        estab_dv_accept_hat(
            $connection,
            $incidentId,
            (int) $assignment['dienstbesetzung_id'],
            'evi'
        );
        if ($function === 'S2') {
            $evidenceAssignmentId = (int) $assignment['dienstbesetzung_id'];
        }
    }
    $assert(
        $evidenceAssignmentId > 0,
        'evidence fixture did not create the selected S2 duty assignment'
    );
    $actor['duty_assignment_id'] = $evidenceAssignmentId;
    estab_dv_activate_initial_shift(
        $connection,
        $incidentId,
        $evidenceShiftId,
        'evidence-integration'
    );

    $connection->begin_transaction();
    try {
        $statement = $connection->prepare(
            'INSERT INTO `nv_nachrichten`'
            . ' (`einsatz_id`, `11_rufnummer`, `12_betreff`, `12_inhalt`,'
            . ' `x00_status`, `x01_abschluss`)'
            . " VALUES (?, ?, ?, ?, 8, 't')"
        );
        $number = '0711 123456';
        $subject = 'Lagemeldung';
        $body = 'Abgeschlossene Testnachricht';
        $statement->bind_param(
            'isss',
            $incidentId,
            $number,
            $subject,
            $body
        );
        $statement->execute();
        $messageId = (int) $connection->insert_id;
        $statement->close();
        $eventId = estab_message_event_append(
            $connection,
            $incidentId,
            $messageId,
            'angelegt',
            $actor,
            null,
            8,
            ['12_inhalt' => $body, 'x00_status' => 8],
            '2026-07-30T12:34'
        );
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    $assert($messageId > 0 && $eventId > 0, 'atomic message evidence insert failed');

    $connection->begin_transaction();
    try {
        $lock = $connection->query(
            'SELECT `00_lfd` FROM `nv_nachrichten`'
            . ' WHERE `00_lfd` = ' . $messageId . ' FOR UPDATE'
        );
        $lock->free();
        $connection->query(
            "UPDATE `nv_nachrichten` SET `17_vermerke` = 'Nachtrag'"
            . ' WHERE `00_lfd` = ' . $messageId
        );
        $secondEvent = estab_message_event_append(
            $connection,
            $incidentId,
            $messageId,
            'vermerk_ergaenzt',
            $actor,
            8,
            8,
            ['17_vermerke' => 'Nachtrag', 'x00_status' => 8],
            '2026-07-30 12:35:01.123456'
        );
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    $assert($secondEvent > $eventId, 'second hash-chain event was not appended');
    $verified = estab_message_evidence_verify($connection, $incidentId);
    $assert(
        $verified['valid']
            && $verified['event_count'] === 2
            && $verified['message_count'] === 1,
        'valid message evidence chain did not verify'
    );
    $v2FieldSnapshot = json_decode(
        (string) $scalar(
            $connection,
            'SELECT `field_snapshot` FROM `nv_nachrichten_ereignisse`'
                . ' WHERE `event_id` = ' . $secondEvent
        ),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    $assert(
        is_array($v2FieldSnapshot)
            && ($v2FieldSnapshot['terminal_snapshot_version'] ?? null)
                === ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V2
            && (
                $v2FieldSnapshot['terminal_message']['11_rufnummer'] ?? null
            ) === $number
            && (
                $v2FieldSnapshot['terminal_message']['12_betreff'] ?? null
            ) === $subject,
        'new terminal event did not persist the complete V2 snapshot'
    );

    // Reproduce a byte-compatible terminal event written before the V2
    // fields existed. It deliberately has no version marker; the verifier
    // must interpret it as V1 and compare the live row with the V1 field set.
    $connection->begin_transaction();
    try {
        $legacyStatement = $connection->prepare(
            'INSERT INTO `nv_nachrichten`'
                . ' (`einsatz_id`, `12_inhalt`, `x00_status`,'
                . ' `x01_abschluss`)'
                . " VALUES (?, 'Historischer V1-Nachweis', 8, 't')"
        );
        $legacyStatement->bind_param('i', $incidentId);
        $legacyStatement->execute();
        $legacyMessageId = (int) $connection->insert_id;
        $legacyStatement->close();

        $legacyResult = $connection->query(
            'SELECT * FROM `nv_nachrichten` WHERE `00_lfd` = '
                . $legacyMessageId . ' FOR UPDATE'
        );
        $legacyMessage = $legacyResult->fetch_assoc();
        $legacyResult->free();
        if (!is_array($legacyMessage)) {
            throw new RuntimeException('Could not read historic V1 fixture');
        }
        $legacyTerminal = estab_message_terminal_snapshot(
            $legacyMessage,
            ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
        );
        $legacyTerminalDigest = estab_message_terminal_snapshot_sha256(
            $legacyMessage,
            ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
        );
        $legacyFieldSnapshot = estab_message_evidence_snapshot([
            'terminal_message' => $legacyTerminal,
            'terminal_snapshot_sha256' => $legacyTerminalDigest,
        ]);
        $legacySnapshotDigest = hash('sha256', $legacyFieldSnapshot);
        $legacyOccurredAt = '2026-07-29 11:00:00.000000';
        $legacyRecordedAt = estab_message_evidence_datetime(
            estab_message_evidence_database_now($connection),
            'Erfassungszeit'
        );
        $legacyEventType = 'historischer_v1';
        $legacyActorUser = 'Historischer Nachweis';
        $legacyActorCode = 'alt';
        $legacyActorFunction = 'Si';
        $legacyFromStatus = null;
        $legacyToStatus = 8;
        $legacyPreviousHash = null;
        $legacyEventHash = estab_message_evidence_event_hash(
            $incidentId,
            $legacyMessageId,
            $legacyEventType,
            $legacyOccurredAt,
            $legacyRecordedAt,
            $legacyActorUser,
            $legacyActorCode,
            $legacyActorFunction,
            $legacyFromStatus,
            $legacyToStatus,
            $legacySnapshotDigest,
            $legacyPreviousHash
        );
        $legacyEventStatement = $connection->prepare(
            'INSERT INTO `nv_nachrichten_ereignisse`'
                . ' (`einsatz_id`, `message_id`, `event_type`, `occurred_at`,'
                . ' `recorded_at`, `actor_user`, `actor_code`,'
                . ' `actor_function`, `from_status`, `to_status`,'
                . ' `field_snapshot`, `snapshot_sha256`,'
                . ' `previous_event_sha256`, `event_sha256`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $legacyEventStatement->bind_param(
            'iissssssiissss',
            $incidentId,
            $legacyMessageId,
            $legacyEventType,
            $legacyOccurredAt,
            $legacyRecordedAt,
            $legacyActorUser,
            $legacyActorCode,
            $legacyActorFunction,
            $legacyFromStatus,
            $legacyToStatus,
            $legacyFieldSnapshot,
            $legacySnapshotDigest,
            $legacyPreviousHash,
            $legacyEventHash
        );
        $legacyEventStatement->execute();
        $legacyEventId = (int) $connection->insert_id;
        $legacyEventStatement->close();
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    $legacyDecoded = json_decode(
        (string) $scalar(
            $connection,
            'SELECT `field_snapshot` FROM `nv_nachrichten_ereignisse`'
                . ' WHERE `event_id` = ' . $legacyEventId
        ),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    $verifiedWithV1 = estab_message_evidence_verify(
        $connection,
        $incidentId
    );
    $assert(
        is_array($legacyDecoded)
            && !array_key_exists(
                'terminal_snapshot_version',
                $legacyDecoded
            )
            && !array_key_exists(
                '11_rufnummer',
                $legacyDecoded['terminal_message']
            )
            && !array_key_exists(
                '12_betreff',
                $legacyDecoded['terminal_message']
            )
            && $verifiedWithV1['valid']
            && $verifiedWithV1['event_count'] === 3
            && $verifiedWithV1['message_count'] === 2
            && $verifiedWithV1['terminal_binding_complete'],
        'implicit V1 terminal evidence no longer verifies against the live row'
    );

    $assert(
        $fails(static function () use ($connection, $incidentId, $messageId): void {
            $connection->query(
                'INSERT INTO `nv_nachrichten_ereignisse`'
                . ' (`einsatz_id`, `message_id`, `event_type`, `occurred_at`,'
                . ' `recorded_at`, `actor_user`, `actor_code`,'
                . ' `actor_function`, `field_snapshot`, `snapshot_sha256`,'
                . ' `event_sha256`) VALUES ('
                . $incidentId . ', ' . $messageId . ", 'forged', NOW(6),"
                . " NOW(6), 'forged', '', 'Si', '{}',"
                . " REPEAT('0',64), REPEAT('0',64))"
            );
        }) instanceof mysqli_sql_exception,
        'database accepted a forged snapshot/event hash'
    );
    foreach (['UPDATE', 'DELETE'] as $verb) {
        $assert(
            $fails(static function () use ($connection, $eventId, $verb): void {
                $sql = $verb === 'UPDATE'
                    ? "UPDATE `nv_nachrichten_ereignisse` SET `event_type` = 'x'"
                        . ' WHERE `event_id` = ' . $eventId
                    : 'DELETE FROM `nv_nachrichten_ereignisse`'
                        . ' WHERE `event_id` = ' . $eventId;
                $connection->query($sql);
            }) instanceof mysqli_sql_exception,
            'message evidence ' . strtolower($verb) . ' was not blocked'
        );
    }

    $entryId = estab_logbook_insert_entry(
        $databaseConfig,
        'nv_etb',
        'etb',
        [
            'event' => 'Einsatzleitung trifft Entscheidung',
            'comment' => 'Nachricht als Entscheidungsgrundlage',
            'event_time' => date('Y-m-d H:i:s', time() - 120),
            'event_type' => 'entscheidung',
            'message_id' => $messageId,
            'attachment_id' => null,
            'reference' => 'Beschluss 1',
            'correction_of' => null,
        ],
        $actor
    );
    $correctionId = estab_logbook_insert_entry(
        $databaseConfig,
        'nv_etb',
        'etb',
        [
            'event' => 'Berichtigung des Beschlusstextes',
            'comment' => 'Original bleibt erhalten',
            'event_time' => date('Y-m-d H:i:s', time() - 60),
            'event_type' => 'korrektur',
            'message_id' => $messageId,
            'attachment_id' => null,
            'reference' => null,
            'correction_of' => $entryId,
        ],
        $actor
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $assert(
        $correctionId > $entryId
            && (int) $scalar(
                $connection,
                'SELECT `estab_correction_of` FROM `nv_etb`'
                . ' WHERE `etb_lfd-nr` = ' . $correctionId
            ) === $entryId,
        'ETB correction did not append a reference to the original'
    );
    $assert(
        $fails(static fn (): bool => $connection->query(
            "UPDATE `nv_etb` SET `etb_aktion` = 'tampered'"
            . ' WHERE `etb_lfd-nr` = ' . $entryId
        )) instanceof mysqli_sql_exception,
        'ETB original was mutable'
    );
    $assert(
        $fails(static fn (): bool => $connection->query(
            'DELETE FROM `nv_etb` WHERE `etb_lfd-nr` = ' . $entryId
        )) instanceof mysqli_sql_exception,
        'ETB original was deletable'
    );

    foreach ([
        'message_id' => $foreignMessageId,
        'attachment_id' => $foreignAttachmentId,
        'correction_of' => $foreignEntryId,
    ] as $referenceField => $referenceId) {
        $crossEntry = [
            'event' => 'Unzulässiger einsatzfremder Bezug',
            'comment' => '',
            'event_time' => date('Y-m-d H:i:s'),
            'event_type' => $referenceField === 'correction_of'
                ? 'korrektur'
                : 'information',
            $referenceField => $referenceId,
        ];
        $assert(
            $fails(static fn (): int => estab_logbook_insert_entry(
                $databaseConfig,
                'nv_etb',
                'etb',
                $crossEntry,
                $actor
            )) instanceof EstabIncidentConflictException,
            'application accepted cross-incident ETB ' . $referenceField
        );
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    foreach ([
        'estab_message_id' => $foreignMessageId,
        'estab_attachment_id' => $foreignAttachmentId,
        'estab_correction_of' => $foreignEntryId,
    ] as $referenceColumn => $referenceId) {
        $eventType = $referenceColumn === 'estab_correction_of'
            ? 'korrektur'
            : 'information';
        $assert(
            $fails(static fn (): bool => $connection->query(
                'INSERT INTO `nv_etb`'
                . ' (`einsatz_id`, `etb_time`, `etb_aktion`, `etb_bemerk`,'
                . ' `estab_event_time`, `estab_event_type`, `'
                . $referenceColumn . '`) VALUES ('
                . $incidentId . ", NOW(), 'cross incident', '', NOW(), '"
                . $eventType . "', " . $referenceId . ')'
            )) instanceof mysqli_sql_exception,
            'database accepted cross-incident ETB ' . $referenceColumn
        );
    }

    $chainedCorrection = [
        'event' => 'Unzulässige Korrektur einer Korrektur',
        'comment' => '',
        'event_time' => date('Y-m-d H:i:s'),
        'event_type' => 'korrektur',
        'correction_of' => $correctionId,
    ];
    $assert(
        $fails(static fn (): int => estab_logbook_insert_entry(
            $databaseConfig,
            'nv_etb',
            'etb',
            $chainedCorrection,
            $actor
        )) instanceof EstabIncidentConflictException,
        'application accepted an ambiguous ETB correction chain'
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $assert(
        $fails(static fn (): bool => $connection->query(
            'INSERT INTO `nv_etb`'
            . ' (`einsatz_id`, `etb_time`, `etb_aktion`, `etb_bemerk`,'
            . ' `estab_event_time`, `estab_event_type`,'
            . ' `estab_correction_of`) VALUES ('
            . $incidentId . ", NOW(), 'correction chain', '', NOW(),"
            . " 'korrektur', " . $correctionId . ')'
        )) instanceof mysqli_sql_exception,
        'database accepted an ambiguous ETB correction chain'
    );
    $selfReferenceId = 900000001;
    $selfReferenceFailure = $fails(static fn (): bool => $connection->query(
        'INSERT INTO `nv_etb`'
        . ' (`etb_lfd-nr`, `einsatz_id`, `etb_time`, `etb_aktion`,'
        . ' `etb_bemerk`, `estab_event_time`, `estab_event_type`,'
        . ' `estab_correction_of`) VALUES ('
        . $selfReferenceId . ', ' . $incidentId
        . ", NOW(), 'self correction', '', NOW(), 'korrektur', "
        . $selfReferenceId . ')'
    ));
    $assert(
        $selfReferenceFailure instanceof mysqli_sql_exception
            && str_contains(
                $selfReferenceFailure->getMessage(),
                'cannot reference itself'
            ),
        'database accepted an ETB correction self-reference'
    );

    estab_dv_close_shift(
        $connection,
        $incidentId,
        $evidenceShiftId,
        'evidence-integration'
    );
    foreach ([1, 4] as $closedStatus) {
        if ($closedStatus === 1) {
            $connection->query(
                'INSERT INTO `nv_anhang`'
                . ' (`filename`, `status`, `integrity_required`,'
                . ' `ingest_sha256`, `ingest_size`,'
                . ' `integrity_captured_at`) VALUES ('
                . "'DV10001', 1, 1,"
                . " SHA2('dv-finished-attachment', 256),"
                . " LENGTH('dv-finished-attachment'), NOW(6))"
            );
        } else {
            $connection->query(
                'INSERT INTO `nv_anhang` (`filename`, `status`) VALUES ('
                . "'DV40001', 4)"
            );
        }
    }
    $connection->query(
        "INSERT INTO `nv_anhang` (`filename`, `status`, `id`)"
        . " VALUES ('DV80001', 8, 'reservation')"
    );
    $reservationId = (int) $connection->insert_id;
    $preflight = estab_incident_close_preflight($connection, $incidentId);
    $assert(
        $preflight['incomplete_attachments'] === 1 && !$preflight['closable'],
        'status 8 reservation did not block formal close'
    );
    $connection->query(
        'UPDATE `nv_anhang` SET `status` = 2'
        . ' WHERE `lfd-nr` = ' . $reservationId
    );
    $assert(
        estab_incident_close_preflight(
            $connection,
            $incidentId
        )['incomplete_attachments'] === 1,
        'status 2 claimed upload did not block formal close'
    );
    $connection->query(
        "UPDATE `nv_anhang` SET `status` = 1,"
        . " `integrity_required` = 1,"
        . " `ingest_sha256` = SHA2('dv-reservation', 256),"
        . " `ingest_size` = LENGTH('dv-reservation'),"
        . " `integrity_captured_at` = NOW(6)"
        . ' WHERE `lfd-nr` = ' . $reservationId
    );
    $preflight = estab_incident_close_preflight($connection, $incidentId);
    $assert(
        $preflight['incomplete_attachments'] === 0 && $preflight['closable'],
        'finished/free attachment states incorrectly block formal close'
    );
    $assert(
        $fails(static fn (): bool => $connection->query(
            'UPDATE `nv_anhang`'
            . " SET `ingest_sha256` = REPEAT('0', 64)"
            . ' WHERE `lfd-nr` = ' . $reservationId
        )) instanceof mysqli_sql_exception,
        'final attachment ingest evidence remained mutable'
    );

    $lastHash = (string) $scalar(
        $connection,
        'SELECT `last_event_sha256` FROM `nv_nachrichten_nachweiskopf`'
        . ' WHERE `message_id` = ' . $messageId
    );
    $connection->query(
        'UPDATE `nv_nachrichten_nachweiskopf`'
        . ' SET `event_count` = `event_count` + 1'
        . ' WHERE `message_id` = ' . $messageId
    );
    $tamperedPreflight = estab_incident_close_preflight($connection, $incidentId);
    $assert(
        !$tamperedPreflight['closable']
            && $tamperedPreflight['evidence_errors'] > 0,
        'formal close did not detect a tampered chain head'
    );
    $connection->query(
        'UPDATE `nv_nachrichten_nachweiskopf` SET `event_count` = 2,'
        . " `last_event_sha256` = '" . $connection->real_escape_string($lastHash) . "'"
        . ' WHERE `message_id` = ' . $messageId
    );
    $assert(
        estab_incident_close_preflight($connection, $incidentId)['closable'],
        'restored evidence chain did not become closable'
    );

    estab_incident_set_legal_hold(
        $connection,
        $incidentId,
        true,
        'Beweissicherung vor Abschluss',
        'evidence-integration'
    );
    $assert(
        $fails(static fn (): bool => $connection->query(
            'DELETE FROM `nv_anhang` WHERE `lfd-nr` = ' . $reservationId
        )) instanceof mysqli_sql_exception,
        'active legal hold did not block operational deletion'
    );
    estab_incident_set_legal_hold(
        $connection,
        $incidentId,
        false,
        null,
        'evidence-integration'
    );

    $closed = estab_incident_close(
        $connection,
        $incidentId,
        $revision,
        'evidence-integration',
        [
            'ende' => date('Y-m-d\TH:i'),
            'close_note' => 'Alle offenen Vorgänge und Nachweise geprüft.',
        ]
    );
    $assert(
        $closed['status'] === 'closed'
            && strtotime((string) $closed['retain_until']) >= time() + 364 * 86400,
        'formal close did not establish immutable one-year retention'
    );
    $assert(
        estab_incident_status($connection)['active_einsatz_id'] === null,
        'formal close did not deactivate the incident'
    );
    $assert(
        $fails(static fn (): array => estab_incident_activate(
            $connection,
            $incidentId,
            (int) $closed['status_revision'],
            'evidence-integration'
        )) instanceof EstabIncidentConflictException,
        'formally closed incident was reactivated'
    );
    $assert(
        $fails(static fn (): bool => $connection->query(
            "UPDATE `nv_nachrichten` SET `12_inhalt` = 'after close'"
            . ' WHERE `00_lfd` = ' . $messageId
        )) instanceof mysqli_sql_exception,
        'normal operational mutation succeeded after formal close'
    );
    $closeAuditId = (int) $scalar(
        $connection,
        "SELECT `ereignis_id` FROM `nv_einsatz_ereignisse`"
        . " WHERE `einsatz_id` = {$incidentId} AND `aktion` = 'abgeschlossen'"
        . ' ORDER BY `ereignis_id` DESC LIMIT 1'
    );
    $assert($closeAuditId > 0, 'formal close audit event is missing');
    $assert(
        $fails(static fn (): bool => $connection->query(
            "UPDATE `nv_einsatz_ereignisse` SET `aktion` = 'tampered'"
            . ' WHERE `ereignis_id` = ' . $closeAuditId
        )) instanceof mysqli_sql_exception,
        'incident lifecycle audit was mutable'
    );
    $assert(
        $fails(static fn (): bool => $connection->query(
            'DELETE FROM `nv_einsatz_ereignisse`'
            . ' WHERE `ereignis_id` = ' . $closeAuditId
        )) instanceof mysqli_sql_exception,
        'incident lifecycle audit was deletable'
    );

    $held = estab_incident_set_legal_hold(
        $connection,
        $incidentId,
        true,
        'Nachträgliche behördliche Aufbewahrung',
        'evidence-integration'
    );
    $assert(
        ($held['estab_legal_hold'] ?? false) === true,
        'legal hold could not be set after formal close'
    );
    $released = estab_incident_set_legal_hold(
        $connection,
        $incidentId,
        false,
        null,
        'evidence-integration'
    );
    $assert(
        ($released['estab_legal_hold'] ?? true) === false
            && (string) $scalar(
                $connection,
                'SELECT `estab_retain_until` FROM `nv_einsaetze`'
                . ' WHERE `einsatz_id` = ' . $incidentId
            ) === (string) $closed['retain_until'],
        'legal hold release shortened the minimum retention boundary'
    );
} finally {
    @$connection->rollback();
    estab_auth_close($connection);
}

echo "DV evidence integration: OK ({$assertions} assertions)\n";
