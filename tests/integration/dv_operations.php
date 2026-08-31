<?php

declare(strict_types=1);

if (getenv('ESTAB_DV_OPERATIONS_INTEGRATION') !== '1') {
    fwrite(STDERR, "ESTAB_DV_OPERATIONS_INTEGRATION=1 is required\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/app/dv_operations.php';
require_once dirname(__DIR__, 2) . '/app/logbook.php';
require_once dirname(__DIR__, 2) . '/app/attachment.php';
require_once dirname(__DIR__, 2) . '/app/category.php';
require_once dirname(__DIR__, 2) . '/app/message_evidence.php';
require_once dirname(__DIR__, 2) . '/app/read_authorization.php';

$databaseName = getenv('ESTAB_DB_NAME') ?: '';
if (preg_match('/\Aestab_dv_operations_[a-z0-9_]*\z/D', $databaseName) !== 1) {
    fwrite(
        STDERR,
        "Refusing to run DV operations integration outside an isolated database\n"
    );
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
    fwrite(STDERR, "DV operations integration database password is required\n");
    exit(2);
}
$databaseConfig = [
    'server' => (getenv('ESTAB_DB_HOST') ?: 'db')
        . ':' . (getenv('ESTAB_DB_PORT') ?: '3306'),
    'user' => getenv('ESTAB_DB_USER') ?: 'root',
    'password' => $password,
    'datenbank' => $databaseName,
];
unset($password);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = estab_auth_connect($databaseConfig);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (($argv[1] ?? '') === '--telecom-revision-worker') {
    $incidentId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
    $sourcePlanId = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT);
    $identityJson = $argv[4] ?? '';
    $readyFile = $argv[5] ?? '';
    try {
        $identity = json_decode(
            (string) $identityJson,
            true,
            8,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        $identity = null;
    }
    if (
        !is_int($incidentId)
        || $incidentId < 1
        || !is_int($sourcePlanId)
        || $sourcePlanId < 1
        || !is_array($identity)
        || !is_string($readyFile)
        || $readyFile === ''
        || !touch($readyFile)
    ) {
        fwrite(STDERR, "Invalid telecommunications contender arguments\n");
        estab_auth_close($connection);
        exit(2);
    }
    $startedAt = hrtime(true);
    $status = 'error';
    $result = null;
    $errorClass = null;
    $errorMessage = null;
    try {
        $result = estab_dv_start_telecom_plan_revision(
            $connection,
            $incidentId,
            $sourcePlanId,
            $identity
        );
        $status = 'success';
    } catch (EstabDvConflictException $exception) {
        $status = 'conflict';
        $errorClass = $exception::class;
        $errorMessage = $exception->getMessage();
    } catch (Throwable $exception) {
        $errorClass = $exception::class;
        $errorMessage = $exception->getMessage();
    } finally {
        estab_auth_close($connection);
    }
    echo json_encode(
        [
            'status' => $status,
            'elapsed_ms' => (hrtime(true) - $startedAt) / 1_000_000,
            'result' => $result,
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
    ) . "\n";
    exit($status === 'error' ? 1 : 0);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (
    string $expectedClass,
    callable $operation,
    string $message
) use ($assert): Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        $assert(
            $exception instanceof $expectedClass,
            $message . ' (got ' . $exception::class . ')'
        );
        return $exception;
    }
    $assert(false, $message . ' (no exception)');
    throw new LogicException('unreachable');
};
$expectLockWaitTimeout = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (mysqli_sql_exception $exception) {
        $assert(
            (int) $exception->getCode() === 1205,
            $message . ' (got database error ' . $exception->getCode() . ')'
        );
        return;
    }
    $assert(false, $message . ' (mutation was not serialized)');
};
$scalar = static function (
    mysqli $connection,
    string $sql,
    string $types = '',
    mixed ...$parameters
): mixed {
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare DV integration scalar');
    }
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        $statement->execute();
        $row = $statement->get_result()->fetch_row();
        return is_array($row) ? ($row[0] ?? null) : null;
    } finally {
        $statement->close();
    }
};
$startTelecomRevisionWorker = static function (
    int $incidentId,
    int $sourcePlanId,
    array $identity,
    string $readyFile
): array {
    $command = [
        PHP_BINARY,
        '-d',
        'auto_prepend_file=',
        __FILE__,
        '--telecom-revision-worker',
        (string) $incidentId,
        (string) $sourcePlanId,
        json_encode(
            $identity,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
        ),
        $readyFile,
    ];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException(
            'Telecommunications contender could not be started'
        );
    }
    return [
        'process' => $process,
        'pipes' => $pipes,
        'ready_file' => $readyFile,
    ];
};
$finishTelecomRevisionWorker = static function (array $worker): array {
    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    foreach ($worker['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $exitCode = proc_close($worker['process']);
    if ($exitCode !== 0) {
        throw new RuntimeException(
            'Telecommunications contender failed: '
            . trim((string) $stderr)
        );
    }
    try {
        $result = json_decode(
            trim((string) $stdout),
            true,
            16,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        throw new RuntimeException(
            'Telecommunications contender returned invalid evidence: '
            . trim((string) $stdout),
            0,
            $exception
        );
    }
    if (!is_array($result)) {
        throw new RuntimeException(
            'Telecommunications contender returned no evidence'
        );
    }
    return $result;
};
$telecomPlanById = static function (
    mysqli $connection,
    int $incidentId,
    int $planId
): array {
    foreach (estab_dv_telecom_plans($connection, $incidentId) as $plan) {
        if ((int) ($plan['fernmeldeplan_id'] ?? 0) === $planId) {
            return $plan;
        }
    }
    throw new RuntimeException(
        'Telecommunications plan fixture could not be read: ' . $planId
    );
};
$createLegacyTelecomDraft = static function (
    mysqli $connection,
    int $incidentId,
    array $identity,
    string $label
): array {
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $identity,
            $label
        ): array {
            if ((int) ($incident['active_einsatz_id'] ?? 0) !== $incidentId) {
                throw new RuntimeException(
                    'Legacy telecommunications fixture lost its active incident'
                );
            }
            $selected = estab_dv_require_write_capability(
                $connection,
                $incidentId,
                $identity,
                'FERNMELDEPLANUNG'
            );
            $versionResult = $connection->query(
                'SELECT COALESCE(MAX(`version`), 0) + 1 AS `version`'
                    . ' FROM `nv_fernmeldeplaene`'
                    . ' WHERE `einsatz_id` = ' . $incidentId
                    . ' FOR UPDATE'
            );
            if (!$versionResult) {
                throw new RuntimeException(
                    'Legacy telecommunications version could not be reserved'
                );
            }
            try {
                $version = (int) (
                    $versionResult->fetch_assoc()['version'] ?? 0
                );
            } finally {
                $versionResult->free();
            }
            $insertPlan = $connection->prepare(
                'INSERT INTO `nv_fernmeldeplaene`'
                    . ' (`einsatz_id`, `version`, `einsatzbezeichnung`,'
                    . ' `herkunft`, `gueltig_ab`, `gueltig_bis`,'
                    . ' `betriebsleitung`, `bemerkungen`, `erstellt_von`)'
                    . ' VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 1 MINUTE),'
                    . ' DATE_ADD(NOW(), INTERVAL 1 DAY), ?, ?, ?)'
            );
            if (!$insertPlan) {
                throw new RuntimeException(
                    'Legacy telecommunications plan could not be prepared'
                );
            }
            $incidentLabel = 'Legacy · ' . $label;
            $origin = 'Vor Versionsworkflow · ' . $label;
            $operationsLead = 'LdF Legacy';
            $notes = 'Absichtlich mit dem früheren plan_created-Nachweis erzeugt.';
            $actor = (string) $selected['kuerzel'];
            try {
                $insertPlan->bind_param(
                    'iisssss',
                    $incidentId,
                    $version,
                    $incidentLabel,
                    $origin,
                    $operationsLead,
                    $notes,
                    $actor
                );
                $planId = estab_dv_positive_id(
                    estab_dv_with_sql_authority_context(
                        $connection,
                        estab_dv_authority_assignment_id($selected),
                        null,
                        static function () use (
                            $connection,
                            $insertPlan
                        ): int {
                            if (!$insertPlan->execute()) {
                                throw new RuntimeException(
                                    'Legacy telecommunications plan could not be inserted'
                                );
                            }
                            return (int) $connection->insert_id;
                        }
                    ),
                    'Legacy telecommunications plan'
                );
            } finally {
                $insertPlan->close();
            }
            $insertEntry = $connection->prepare(
                'INSERT INTO `nv_fernmeldeplan_eintraege`'
                    . ' (`fernmeldeplan_id`, `sortierung`, `betriebsstelle`,'
                    . ' `rufname`, `medium`, `kanal`, `bandlage`,'
                    . ' `verkehrsform`, `besondere_vermerke`, `bemerkungen`)'
                    . " VALUES (?, 1, ?, ?, 'Fu', 'TMO 401', 'G/U',"
                    . " 'Gegenverkehr', '', '')"
            );
            if (!$insertEntry) {
                throw new RuntimeException(
                    'Legacy telecommunications entry could not be prepared'
                );
            }
            $station = 'Legacy-Gegenstelle ' . $label;
            $callSign = 'Legacy ' . $version;
            try {
                $insertEntry->bind_param('iss', $planId, $station, $callSign);
                $insertEntry->execute();
                $entryId = (int) $connection->insert_id;
            } finally {
                $insertEntry->close();
            }
            // This is intentionally the exact evidence shape emitted by the
            // pre-versioning application: no source_plan_id was recorded.
            estab_dv_audit(
                $connection,
                'nv_protokoll',
                $incidentId,
                'DV Fernmeldeplan',
                [
                    'action' => 'plan_created',
                    'plan_id' => $planId,
                    'plan_version' => $version,
                    'actor' => $actor,
                ]
            );
            return [
                'fernmeldeplan_id' => $planId,
                'fernmeldeplan_eintrag_id' => $entryId,
                'version' => $version,
            ];
        }
    );
};
$telecomPlanEvent = static function (
    mysqli $connection,
    int $incidentId,
    int $planId,
    string $action
): array {
    $statement = $connection->prepare(
        'SELECT `sequenz`, `ereignis_hash`,'
            . ' CAST(`details` AS CHAR) AS `details_json`'
            . ' FROM `nv_betriebsereignisse`'
            . ' WHERE `einsatz_id` = ?'
            . " AND `objekttyp` = 'FERNMELDEPLAN'"
            . ' AND `objekt_id` = ? AND BINARY `aktion` = BINARY ?'
            . ' ORDER BY `sequenz` DESC LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Telecommunications plan event could not be prepared'
        );
    }
    try {
        $statement->bind_param('iis', $incidentId, $planId, $action);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (!is_array($row) || !is_string($row['details_json'] ?? null)) {
        throw new RuntimeException(
            'Telecommunications plan event could not be read: ' . $action
        );
    }
    $details = json_decode(
        $row['details_json'],
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($details)) {
        throw new RuntimeException(
            'Telecommunications plan event details are invalid: ' . $action
        );
    }
    return [
        'sequence' => (int) $row['sequenz'],
        'event_hash' => (string) $row['ereignis_hash'],
        'details' => $details,
    ];
};
$telecomEntryEvent = static function (
    mysqli $connection,
    int $incidentId,
    int $planId,
    int $entryId,
    string $action
): array {
    $statement = $connection->prepare(
        'SELECT `sequenz`, `ereignis_hash`,'
            . ' CAST(`details` AS CHAR) AS `details_json`'
            . ' FROM `nv_betriebsereignisse`'
            . ' WHERE `einsatz_id` = ?'
            . " AND `objekttyp` = 'FERNMELDEPLAN'"
            . ' AND `objekt_id` = ? AND BINARY `aktion` = BINARY ?'
            . ' AND CAST(JSON_UNQUOTE(JSON_EXTRACT('
            . " `details`, '$.entry_id')) AS UNSIGNED) = ?"
            . ' ORDER BY `sequenz` DESC LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Telecommunications entry event could not be prepared'
        );
    }
    try {
        $statement->bind_param(
            'iisi',
            $incidentId,
            $planId,
            $action,
            $entryId
        );
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (!is_array($row) || !is_string($row['details_json'] ?? null)) {
        throw new RuntimeException(
            'Telecommunications entry event could not be read: ' . $action
        );
    }
    $details = json_decode(
        $row['details_json'],
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($details)) {
        throw new RuntimeException(
            'Telecommunications entry event details are invalid: ' . $action
        );
    }
    return [
        'sequence' => (int) $row['sequenz'],
        'event_hash' => (string) $row['ereignis_hash'],
        'details' => $details,
    ];
};
$latestTelecomProtocol = static function (
    mysqli $connection,
    int $incidentId
) use ($scalar): array {
    $payload = $scalar(
        $connection,
        'SELECT `p_ereignis` FROM `nv_protokoll`'
            . ' WHERE `einsatz_id` = ?'
            . " AND `p_was` = 'DV Fernmeldeplan'"
            . ' ORDER BY `p_lfd` DESC LIMIT 1',
        'i',
        $incidentId
    );
    if (!is_string($payload)) {
        throw new RuntimeException(
            'Latest legacy telecommunications audit could not be read'
        );
    }
    $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            'Latest legacy telecommunications audit is invalid'
        );
    }
    return ['payload' => $payload, 'details' => $decoded];
};
$logbookEvidenceSnapshot = static function (
    mysqli $connection,
    int $incidentId
) use ($scalar): array {
    return [
        'etb' => (string) $scalar(
            $connection,
            "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(`estab_book_lfd`), 0))"
                . ' FROM `nv_etb` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'ttb' => (string) $scalar(
            $connection,
            "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(`estab_book_lfd`), 0))"
                . ' FROM `nv_tbb` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'heads' => (string) $scalar(
            $connection,
            "SELECT COALESCE(GROUP_CONCAT(CONCAT(`buchart`, ':', `next_lfd`)"
                . " ORDER BY `buchart` SEPARATOR ','), '')"
                . ' FROM `nv_logbuch_koepfe` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'dv_events' => (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_betriebsereignisse`'
                . ' WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'dv_event_head' => (string) ($scalar(
            $connection,
            "SELECT CONCAT(`letzte_sequenz`, ':', `letzter_hash`)"
                . ' FROM `nv_betriebsereignis_kopf` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ) ?? ''),
        'incident_events' => (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_einsatz_ereignisse`'
                . ' WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'protocol' => (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_protokoll` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
    ];
};
$handoverStateSnapshot = static function (
    mysqli $connection,
    int $incidentId,
    int $requestId
) use ($scalar): array {
    return [
        'shifts' => (string) $scalar(
            $connection,
            "SELECT COALESCE(GROUP_CONCAT(CONCAT(`dienstschicht_id`, ':',"
                . " `status`, ':', COALESCE(CAST(`aktiviert_am` AS CHAR), ''),"
                . " ':', COALESCE(CAST(`beendet_am` AS CHAR), ''))"
                . " ORDER BY `dienstschicht_id` SEPARATOR ','), '')"
                . ' FROM `nv_dienstschichten` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'request' => (string) ($scalar(
            $connection,
            "SELECT CONCAT(`status`, ':',"
                . " COALESCE(CAST(`bestaetigt_am` AS CHAR), ''), ':',"
                . " COALESCE(`bestaetigt_von`, ''), ':',"
                . " COALESCE(`bestaetigt_mit_besetzung_id`, 0), ':',"
                . " COALESCE(`dienstuebergabe_id`, 0))"
                . ' FROM `nv_dienstuebergabe_anfragen`'
                . ' WHERE `dienstuebergabe_anfrage_id` = ?',
            'i',
            $requestId
        ) ?? ''),
        'handovers' => (string) $scalar(
            $connection,
            "SELECT CONCAT(COUNT(*), ':',"
                . ' COALESCE(MAX(`dienstuebergabe_id`), 0))'
                . ' FROM `nv_dienstuebergaben` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'assignments' => (string) $scalar(
            $connection,
            "SELECT COALESCE(GROUP_CONCAT(CONCAT("
                . " assignment.`dienstbesetzung_id`, ':', assignment.`status`,"
                . " ':', COALESCE(CAST(assignment.`abgeloest_am` AS CHAR), ''),"
                . " ':', COALESCE(assignment.`nachfolger_id`, 0))"
                . " ORDER BY assignment.`dienstbesetzung_id` SEPARATOR ','), '')"
                . ' FROM `nv_dienstbesetzungen` AS assignment'
                . ' JOIN `nv_dienstschichten` AS shift_row'
                . ' ON shift_row.`dienstschicht_id` ='
                . ' assignment.`dienstschicht_id`'
                . ' WHERE shift_row.`einsatz_id` = ?',
            'i',
            $incidentId
        ),
    ];
};

$suffix = substr(bin2hex(random_bytes(4)), 0, 4);
$codes = [
    's2' => 'a' . $suffix,
    'si' => 'b' . $suffix,
    'si_duplicate' => 'c' . $suffix,
    'ldf' => 'd' . $suffix,
    'ldf_duplicate' => 'e' . $suffix,
    'aw' => 'f' . $suffix,
    'messenger' => 'g' . $suffix,
    's6' => 'h' . $suffix,
    's2_successor' => 'i' . $suffix,
    's1_extension' => 'j' . $suffix,
    'aw_extension' => 'k' . $suffix,
    's3' => 'l' . $suffix,
    'etb' => 'm' . $suffix,
];
$actor = 'dv-operations-integration';
$assignments = [];
$secondAssignments = [];
$incidentId = 0;
$messageId = 0;
$conversationMessageId = 0;
$sessionStarted = false;
$strictAttachmentContext = null;

$phpSessionId = 'dvops' . $suffix;
if (session_status() !== PHP_SESSION_NONE) {
    throw new RuntimeException('DV operations integration session already started');
}
session_id($phpSessionId);
if (!session_start()) {
    throw new RuntimeException('Could not start DV operations integration session');
}
$phpSessionId = session_id();
if (!estab_auth_session_id_is_valid($phpSessionId)) {
    throw new RuntimeException('PHP generated an invalid integration session id');
}
$sessionStarted = true;

try {
    $assert(
        estab_dv_with_sql_authority_context(
            $connection,
            17,
            23,
            static fn (): string => (string) $scalar(
                $connection,
                'SELECT CONCAT(@estab_dv_actor_assignment_id, ?, '
                    . '@estab_dv_target_assignment_id)',
                's',
                '|'
            )
        ) === '17|23'
            && (string) $scalar(
                $connection,
                'SELECT CONCAT(@estab_dv_actor_assignment_id IS NULL, ?, '
                    . '@estab_dv_target_assignment_id IS NULL)',
                's',
                '|'
            ) === '1|1',
        'assignment context was not exact or remained set after success'
    );
    $expect(
        RuntimeException::class,
        static fn (): mixed => estab_dv_with_sql_authority_context(
            $connection,
            31,
            null,
            static function (): never {
                throw new RuntimeException('authority-context-probe');
            }
        ),
        'assignment context did not propagate its protected exception'
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT CONCAT(@estab_dv_actor_assignment_id IS NULL, ?, '
                . '@estab_dv_target_assignment_id IS NULL)',
            's',
            '|'
        ) === '1|1',
        'assignment context remained set after an exception'
    );
    $connection->query(
        'SET @estab_dv_actor_assignment_id = 41,'
        . ' @estab_dv_target_assignment_id = 43'
    );
    $expect(
        LogicException::class,
        static fn (): mixed => estab_dv_with_sql_authority_context(
            $connection,
            47,
            53,
            static fn (): bool => true
        ),
        'stale assignment context was accepted or nested'
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT CONCAT(@estab_dv_actor_assignment_id IS NULL, ?, '
                . '@estab_dv_target_assignment_id IS NULL)',
            's',
            '|'
        ) === '1|1',
        'stale assignment context was not discarded fail-closed'
    );
    $assert(
        estab_logbook_lifecycle_with_system_write_context(
            $connection,
            59,
            'ETB',
            static fn (): string => (string) $scalar(
                $connection,
                'SELECT CONCAT('
                    . '@estab_logbook_system_write_incident_id, ?, '
                    . '@estab_logbook_system_write_book)',
                's',
                '|'
            )
        ) === '59|ETB'
            && (string) $scalar(
                $connection,
                'SELECT CONCAT('
                    . '@estab_logbook_system_write_incident_id IS NULL, ?, '
                    . '@estab_logbook_system_write_book IS NULL)',
                's',
                '|'
            ) === '1|1',
        'system logbook context was not exact or remained after success'
    );
    $expect(
        RuntimeException::class,
        static fn (): mixed =>
            estab_logbook_lifecycle_with_system_write_context(
                $connection,
                61,
                'TTB',
                static function (): never {
                    throw new RuntimeException('system-context-probe');
                }
            ),
        'system logbook context did not propagate its protected exception'
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT CONCAT('
                . '@estab_logbook_system_write_incident_id IS NULL, ?, '
                . '@estab_logbook_system_write_book IS NULL)',
            's',
            '|'
        ) === '1|1',
        'system logbook context remained set after an exception'
    );
    $connection->query(
        'SET @estab_logbook_system_write_incident_id = 67,'
        . " @estab_logbook_system_write_book = 'ETB'"
    );
    $expect(
        LogicException::class,
        static fn (): mixed =>
            estab_logbook_lifecycle_with_system_write_context(
                $connection,
                71,
                'TTB',
                static fn (): bool => true
            ),
        'stale system logbook context was accepted or nested'
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT CONCAT('
                . '@estab_logbook_system_write_incident_id IS NULL, ?, '
                . '@estab_logbook_system_write_book IS NULL)',
            's',
            '|'
        ) === '1|1',
        'stale system logbook context was not discarded fail-closed'
    );

    $status = estab_incident_status($connection);
    $assert(
        $status['active_einsatz_id'] === null,
        'isolated operations database unexpectedly has an active incident'
    );
    $created = estab_incident_create(
        $connection,
        [
            'kennung' => 'DV-OPS-' . strtoupper($suffix),
            'name' => 'DV 1-101 Führungsstellenbetrieb',
            'beginn' => date('Y-m-d\TH:i', time() - 3600),
            'ort' => 'Integrationsprüfung',
            'organisation' => 'THW',
            'fuehrungsstellenname' => 'Führungsstelle DV-Operationen',
            'einsatzleitung' => 'Leitung Integration',
            'beschreibung' => 'Isolierter Nachweis der DV-Abläufe.',
        ],
        $actor,
        true,
        (int) $status['revision']
    );
    $incidentId = (int) $created['einsatz_id'];
    $assert($incidentId > 0, 'active DV integration incident was not created');
    $preShiftS2Identity = [
        'benutzer' => 'Lage/Dokumentation',
        'kuerzel' => $codes['s2'],
        'funktion' => 'S2',
        'rolle' => 'Stab',
    ];
    $preShiftAwIdentity = [
        'benutzer' => 'Aufnahme/Weitergabe',
        'kuerzel' => $codes['aw'],
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
    ];
    $insertUser = $connection->prepare(
        'INSERT INTO `nv_benutzer`'
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `aktiv`,'
        . ' `estab_letzte_aktivitaet`, `estab_gesperrt`, `password`)'
        . ' VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(6), 0, ?)'
    );
    if (!$insertUser) {
        throw new RuntimeException('Could not prepare DV integration accounts');
    }
    try {
        $users = [
            [$codes['s2'], 'S2', 'Stab', 'Lage/Dokumentation'],
            [$codes['si'], 'Si', 'Stab', 'Sichter'],
            [$codes['si_duplicate'], 'Si', 'Stab', 'Zweiter Sichter'],
            [$codes['ldf'], 'LdF', 'Fernmelder', 'Leiter FmZt'],
            [
                $codes['ldf_duplicate'],
                'LdF',
                'Fernmelder',
                'Zweiter Leiter FmZt',
            ],
            [$codes['aw'], 'A/W', 'Fernmelder', 'Aufnahme/Weitergabe'],
            [$codes['messenger'], 'A/W', 'Fernmelder', 'Melder'],
            [$codes['s6'], 'S6', 'Stab', 'Fernmeldeplanung'],
            [
                $codes['s2_successor'],
                'S2',
                'Stab',
                'Nachfolgende Lage/Dokumentation',
            ],
            [$codes['s1_extension'], 'S1', 'Stab', 'Schichterweiterung S1'],
            [
                $codes['aw_extension'],
                'A/W',
                'Fernmelder',
                'Zusätzliche Aufnahme/Weitergabe',
            ],
            [$codes['s3'], 'S3', 'Stab', 'Sachgebiet 3'],
            [$codes['etb'], 'ETB', 'Stab', 'Einsatztagebuchführung'],
        ];
        foreach ($users as [$code, $function, $role, $name]) {
            $sessionId = hash_equals($code, $codes['s2'])
                ? $phpSessionId
                : 'dv-session-' . $suffix . '-' . $code;
            $passwordHash = password_hash(
                'DV integration account ' . $code,
                PASSWORD_DEFAULT
            );
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Could not hash integration password');
            }
            $insertUser->bind_param(
                'ssssss',
                $name,
                $code,
                $function,
                $role,
                $sessionId,
                $passwordHash
            );
            $insertUser->execute();
        }
    } finally {
        $insertUser->close();
    }

    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $preShiftS2Identity
        ),
        'STRICT admitted S2 before the first active duty shift'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $preShiftAwIdentity
        ),
        'STRICT admitted A/W before the first active duty shift'
    );

    $shift = estab_dv_create_shift(
        $connection,
        $incidentId,
        'Dienstschicht 1',
        null,
        $actor
    );
    $shiftId = (int) $shift['dienstschicht_id'];
    $hatDefinitions = [
        's2' => 'S2',
        'si' => 'Si',
        'ldf' => 'LdF',
        'aw' => 'A/W',
        'messenger' => 'A/W',
        's6' => 'S6',
    ];
    foreach ($hatDefinitions as $accountKey => $function) {
        $assignment = estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes[$accountKey],
            $function,
            $actor
        );
        $assignments[$accountKey] = (int) $assignment['dienstbesetzung_id'];
    }
    $s3Assignment = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['s3'],
        'S3',
        $actor
    );
    $assignments['s3'] = (int) $s3Assignment['dienstbesetzung_id'];
    $etbAssignment = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['etb'],
        'ETB',
        $actor
    );
    $assignments['etb'] = (int) $etbAssignment['dienstbesetzung_id'];
    $assert(
        count($assignments) === 8,
        'initial and combined function hats were not assigned'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes['si_duplicate'],
            'Si',
            $actor
        ),
        'a second active Sichter was accepted in one shift'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes['ldf_duplicate'],
            'LdF',
            $actor
        ),
        'a second active LdF was accepted in one shift'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_dienstbesetzungen`'
                . ' WHERE `dienstschicht_id` = ?'
                . " AND `funktion` = 'A/W'",
            'i',
            $shiftId
        ) === 2,
        'multiple distinct A/W operators were not accepted'
    );

    foreach ($hatDefinitions as $accountKey => $_function) {
        if ($accountKey === 's6') {
            continue;
        }
        estab_dv_accept_hat(
            $connection,
            $incidentId,
            $assignments[$accountKey],
            $codes[$accountKey]
        );
    }
    estab_dv_accept_hat(
        $connection,
        $incidentId,
        $assignments['s3'],
        $codes['s3']
    );
    estab_dv_accept_hat(
        $connection,
        $incidentId,
        $assignments['etb'],
        $codes['etb']
    );
    $assert(
        estab_dv_shift_required_hats($connection, $shiftId) === ['S6'],
        'a shift without accepted S6 was not held before activation'
    );
    estab_dv_accept_hat(
        $connection,
        $incidentId,
        $assignments['s6'],
        $codes['s6']
    );
    $unacceptedOutgoing = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['ldf_duplicate'],
        'A/W',
        $actor
    );
    $unacceptedOutgoingAssignmentId =
        (int) $unacceptedOutgoing['dienstbesetzung_id'];
    $assert(
        estab_dv_shift_required_hats($connection, $shiftId) === [],
        'accepted initial shift still reports missing mandatory functions'
    );

    $strictHeaderFixture = [
        'organisation' => 'THW',
        'einsatzleitung' => 'Leitung Integration',
        'beschreibung' => 'Isolierter Nachweis der DV-Abläufe.',
        'expected_organisation' => 'THW',
        'expected_einsatzleitung' => 'Leitung Integration',
        'expected_beschreibung' => 'Isolierter Nachweis der DV-Abläufe.',
    ];
    $assert(
        estab_incident_update_logbook_header(
            $connection,
            $incidentId,
            $strictHeaderFixture,
            $actor
        )['organisation'] === 'THW',
        'a merely planned STRICT shift locked the logbook header'
    );
    // Force the final audit write to fail after the STRICT shift and book-open
    // mutations. The surrounding transaction must roll back both atomically.
    $openingRollbackEvidence = $logbookEvidenceSnapshot(
        $connection,
        $incidentId
    );
    $openingRollbackShift = (string) $scalar(
        $connection,
        "SELECT CONCAT(`status`, '|', COALESCE(CAST(`aktiviert_am` AS CHAR), ''))"
            . ' FROM `nv_dienstschichten` WHERE `dienstschicht_id` = ?',
        'i',
        $shiftId
    );
    $expect(
        RuntimeException::class,
        static fn (): null => (
            estab_dv_activate_initial_shift(
                $connection,
                $incidentId,
                $shiftId,
                $actor,
                'estab_test_missing_protocol'
            ) ?? null
        ),
        'an injected opening audit failure did not abort activation'
    );
    $assert(
        $logbookEvidenceSnapshot($connection, $incidentId)
            === $openingRollbackEvidence
            && (string) $scalar(
                $connection,
                "SELECT CONCAT(`status`, '|',"
                    . " COALESCE(CAST(`aktiviert_am` AS CHAR), ''))"
                    . ' FROM `nv_dienstschichten`'
                    . ' WHERE `dienstschicht_id` = ?',
                'i',
                $shiftId
            ) === $openingRollbackShift
            && $openingRollbackShift === 'GEPLANT|',
        'failed historical shift activation retained a partial shift or '
            . 'changed an ETB/TBB row, head '
            . 'or audit event'
    );
    estab_dv_activate_initial_shift(
        $connection,
        $incidentId,
        $shiftId,
        $actor
    );
    $strictSystemEntryCount = (string) $scalar(
        $connection,
        'SELECT CONCAT('
            . '(SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?),'
            . "'|',"
            . '(SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?))',
        'ii',
        $incidentId,
        $incidentId
    );
    foreach (
        [
            'ETB' => [
                'sql' => 'INSERT INTO `nv_etb`'
                    . ' (`einsatz_id`, `etb_time`, `etb_aktion`,'
                    . ' `etb_bemerk`, `etb_benutzer`, `etb_kuerzel`,'
                    . ' `etb_funktion`, `estab_event_time`,'
                    . ' `estab_event_type`) VALUES (' . $incidentId
                    . ", NOW(), 'System ohne Schicht', '',"
                    . " 'eStab-System', 'system', '', NOW(6), 'ohne')",
                'message' => 'STRICT ETB entry requires duty shift provenance',
            ],
            'TTB' => [
                'sql' => 'INSERT INTO `nv_tbb`'
                    . ' (`einsatz_id`, `tbb_time`, `tbb_aktion`,'
                    . ' `tbb_bemerk`, `tbb_benutzer`, `tbb_kuerzel`,'
                    . ' `tbb_funktion`, `estab_event_time`,'
                    . ' `estab_entry_type`, `estab_operations`) VALUES ('
                    . $incidentId
                    . ", NOW(), 'System ohne Schicht', '',"
                    . " 'eStab-System', 'system', '', NOW(6),"
                    . " 'betriebsereignis', 'Fehlender Schichtnachweis')",
                'message' => 'STRICT TTB entry requires duty shift provenance',
            ],
        ] as $book => $probe
    ) {
        $strictSystemFailure = $expect(
            mysqli_sql_exception::class,
            static fn (): mixed =>
                estab_logbook_lifecycle_with_system_write_context(
                    $connection,
                    $incidentId,
                    $book,
                    static fn (): bool => $connection->query($probe['sql'])
                ),
            'STRICT accepted a system ' . $book . ' entry without duty shift'
        );
        $assert(
            str_contains(
                $strictSystemFailure->getMessage(),
                $probe['message']
            ),
            'STRICT system ' . $book
                . ' no-shift rejection was not explicit'
        );
    }
    $assert(
        (string) $scalar(
            $connection,
            'SELECT CONCAT('
                . '(SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?),'
                . "'|',"
                . '(SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?))',
            'ii',
            $incidentId,
            $incidentId
        ) === $strictSystemEntryCount,
        'rejected STRICT system no-shift probes changed ETB/TBB evidence'
    );

    // Before efbc4b9, a first STRICT duty shift could never be activated over
    // pre-existing logbook evidence. Reproduce that boundary with a separate
    // strict incident and prove that the failed activation is atomic.
    $legacyStatus = estab_incident_status($connection);
    $legacyCreated = estab_incident_create(
        $connection,
        [
            'kennung' => 'DV-LEGACY-' . strtoupper($suffix),
            'name' => 'STRICT Altbestand vor Erstschicht',
            'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
            'beginn' => date('Y-m-d\TH:i', time() - 1800),
            'ort' => 'Integrationsprüfung',
            'organisation' => 'THW',
            'fuehrungsstellenname' => 'Führungsstelle Altbestand',
            'einsatzleitung' => 'Leitung Integration',
            'beschreibung' => 'Atomare Erstschichtprüfung mit Altbestand.',
        ],
        $actor,
        false
    );
    $legacyIncidentId = (int) $legacyCreated['einsatz_id'];
    estab_incident_activate(
        $connection,
        $legacyIncidentId,
        (int) $legacyStatus['revision'],
        $actor
    );
    $legacyShift = estab_dv_create_shift(
        $connection,
        $legacyIncidentId,
        'Erstschicht über Altbestand',
        null,
        $actor
    );
    $legacyShiftId = (int) $legacyShift['dienstschicht_id'];
    foreach ($hatDefinitions as $accountKey => $function) {
        $legacyAssignment = estab_dv_assign_hat(
            $connection,
            $legacyIncidentId,
            $legacyShiftId,
            $codes[$accountKey],
            $function,
            $actor
        );
        estab_dv_accept_hat(
            $connection,
            $legacyIncidentId,
            (int) $legacyAssignment['dienstbesetzung_id'],
            $codes[$accountKey]
        );
    }
    $legacyEventTime = date('Y-m-d H:i:s');
    estab_logbook_lifecycle_insert_etb(
        $connection,
        $legacyIncidentId,
        $legacyEventTime,
        'Übernommener ETB-Altbestand',
        'Vor der ersten formalen Dienstschicht vorhanden.',
        'ohne',
        $legacyShiftId
    );
    estab_logbook_lifecycle_insert_ttb_record(
        $connection,
        $legacyIncidentId,
        $legacyEventTime,
        'betriebsereignis',
        ['operations' => 'Übernommener TTB-Altbestand'],
        'Vor der ersten formalen Dienstschicht vorhanden.',
        null,
        null,
        $legacyShiftId
    );
    $legacyActivationEvidence = $logbookEvidenceSnapshot(
        $connection,
        $legacyIncidentId
    );
    $legacyActivationFailure = $expect(
        RuntimeException::class,
        static fn (): null => (
            estab_dv_activate_initial_shift(
                $connection,
                $legacyIncidentId,
                $legacyShiftId,
                $actor
            ) ?? null
        ),
        'STRICT activated a first duty shift over existing ETB/TBB evidence'
    );
    $assert(
        str_contains(
            $legacyActivationFailure->getMessage(),
            'weil bereits Einträge vorhanden sind'
        )
            && $logbookEvidenceSnapshot($connection, $legacyIncidentId)
                === $legacyActivationEvidence
            && (string) $scalar(
                $connection,
                "SELECT CONCAT(`status`, '|',"
                    . " COALESCE(CAST(`aktiviert_am` AS CHAR), ''))"
                    . ' FROM `nv_dienstschichten`'
                    . ' WHERE `dienstschicht_id` = ?',
                'i',
                $legacyShiftId
            ) === 'GEPLANT|',
        'rejected STRICT first-shift activation changed legacy evidence or '
            . 'retained a partial activation'
    );
    $returnStatus = estab_incident_status($connection);
    $returnedIncident = estab_incident_activate(
        $connection,
        $incidentId,
        (int) $returnStatus['revision'],
        $actor
    );
    $assert(
        ($returnedIncident['active_einsatz_id'] ?? null) === $incidentId
            && ($returnedIncident['estab_permission_mode'] ?? null)
                === ESTAB_PERMISSION_MODE_STRICT,
        'STRICT activation rollback fixture did not restore the main incident'
    );
    $listedIncident = array_values(array_filter(
        estab_incident_list($connection),
        static fn (array $incident): bool =>
            (int) $incident['einsatz_id'] === $incidentId
    ));
    $expect(
        EstabIncidentConflictException::class,
        static fn (): array => estab_incident_update_logbook_header(
            $connection,
            $incidentId,
            $strictHeaderFixture,
            $actor
        ),
        'an activated STRICT duty shift did not lock the logbook header'
    );
    $assert(
        count($listedIncident) === 1
            && ($listedIncident[0]['logbuchkopf_gesperrt'] ?? false) === true,
        'the incident list did not expose the STRICT duty-shift header lock'
    );
    $strictAttachmentIdentity = $preShiftS2Identity + [
        'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
        'duty_assignment_id' => $assignments['s2'],
    ];
    estab_permission_context_set_from_incident(
        estab_incident_status($connection)
    );
    $strictAttachmentContext = estab_attachment_origin_context_create(
        $strictAttachmentIdentity,
        $incidentId,
        ['task' => 'Stab_schreiben', '00_lfd' => '']
    );
    $strictAttachmentDraft = estab_attachment_origin_draft_from_request(
        ['task' => 'Stab_schreiben', '12_betreff' => 'Übergabefester Entwurf'],
        $strictAttachmentIdentity,
        $strictAttachmentContext
    );
    $strictAttachmentSession = [];
    estab_attachment_origin_flow_store(
        $strictAttachmentSession,
        $strictAttachmentContext,
        $strictAttachmentDraft
    );
    $assert(
        ($strictAttachmentContext['duty_assignment_id'] ?? null)
            === $assignments['s2']
            && ($strictAttachmentContext['permission_mode'] ?? null)
                === ESTAB_PERMISSION_MODE_STRICT
            && estab_attachment_origin_draft_find(
                $strictAttachmentSession,
                $strictAttachmentContext
            ) === $strictAttachmentDraft,
        'STRICT attachment draft lost its exact assignment/session binding'
    );
    $assert(
        estab_auth_duty_assignment_matches_session(
            $connection,
            $assignments['s2'],
            $codes['s2'],
            'S2',
            'Stab'
        )
            && !estab_auth_duty_assignment_matches_session(
                $connection,
                $assignments['s2'],
                $codes['aw'],
                'S2',
                'Stab'
            )
            && !estab_auth_duty_assignment_matches_session(
                $connection,
                $assignments['s2'],
                $codes['s2'],
                'S3',
                'Stab'
            )
            && !estab_auth_duty_assignment_matches_session(
                $connection,
                $unacceptedOutgoingAssignmentId,
                $codes['ldf_duplicate'],
                'A/W',
                'Fernmelder'
            ),
        'STRICT session validation accepted a foreign, wrong-function or '
            . 'unaccepted duty assignment'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?'
                . ' AND `estab_book_lfd` = 1'
                . " AND `estab_event_type` = 'ohne'"
                . " AND `etb_aktion` LIKE '%Einsatztagebuch eröffnet%'"
                . " AND `etb_aktion` LIKE '%Einsatzbeginn:%'"
                . " AND `etb_aktion` NOT LIKE '%Zweiter Leiter FmZt%'",
            'i',
            $incidentId
        ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_book_lfd` = 1'
                    . " AND `estab_entry_type` = 'betrieb_personal'"
                    . " AND `estab_personnel_duty` LIKE '%Betriebsaufnahme%'",
                'i',
                $incidentId
            ) === 1,
        'incident activation did not open ETB and TBB at local number 1'
    );
    $activeAssignmentCount = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_dienstbesetzungen`'
            . ' WHERE `dienstschicht_id` = ?',
        'i',
        $shiftId
    );
    $activeEtbAssignmentRejection = $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes['si_duplicate'],
            'ETB',
            $actor
        ),
        'active shift accepted an ETB assignment that would replace its writer'
    );
    $assert(
        str_contains(
            $activeEtbAssignmentRejection->getMessage(),
            'dokumentierte und bestätigte Schichtübergabe'
        )
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_dienstbesetzungen`'
                    . ' WHERE `dienstschicht_id` = ?',
                'i',
                $shiftId
            ) === $activeAssignmentCount,
        'rejected active-shift ETB assignment was unclear or mutated the roster'
    );

    // A running shift may be expanded without pretending that personnel were
    // present in opening row 1. Assignment alone is not yet effective; the
    // person's acceptance appends immutable book evidence in the same
    // transaction. A non-Fm function belongs only in the ETB.
    $s1Extension = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['s1_extension'],
        'S1',
        $actor
    );
    $s1ExtensionId = (int) $s1Extension['dienstbesetzung_id'];
    $assert(
        ($s1Extension['active_shift_extension'] ?? null) === true
            && ($s1Extension['schicht_status'] ?? null) === 'AKTIV',
        'active-shift S1 extension was not identified as an extension'
    );
    $s1ExtensionBefore = $logbookEvidenceSnapshot($connection, $incidentId);
    $expect(
        RuntimeException::class,
        static fn (): array => estab_dv_accept_hat(
            $connection,
            $incidentId,
            $s1ExtensionId,
            $codes['s1_extension'],
            'estab_test_missing_protocol'
        ),
        'an injected extension-audit failure did not abort acceptance'
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT `status` FROM `nv_dienstbesetzungen`'
                . ' WHERE `dienstbesetzung_id` = ?',
            'i',
            $s1ExtensionId
        ) === 'ZUGEWIESEN'
            && $logbookEvidenceSnapshot($connection, $incidentId)
                === $s1ExtensionBefore,
        'failed active-shift acceptance retained assignment, ETB/TBB, head '
            . 'or audit mutations'
    );
    $acceptedS1Extension = estab_dv_accept_hat(
        $connection,
        $incidentId,
        $s1ExtensionId,
        $codes['s1_extension']
    );
    $assert(
        ($acceptedS1Extension['active_shift_extension'] ?? null) === true
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?'
                    . " AND `etb_aktion` LIKE '%Schichtbesetzung erweitert%'"
                    . " AND `etb_aktion` LIKE '%Schichterweiterung S1%'",
                'i',
                $incidentId
            ) === 1
            && (string) $scalar(
                $connection,
                "SELECT CONCAT(COUNT(*), ':',"
                    . " COALESCE(MAX(`estab_book_lfd`), 0)) FROM `nv_tbb`"
                    . ' WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === $s1ExtensionBefore['ttb'],
        'accepted S1 extension was not written exactly once to the ETB only'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes['si_duplicate'],
            'S1',
            $actor
        ),
        'an occupied non-A/W function was replaced inside the active shift'
    );

    // A/W is deliberately multi-seat. Additional operating personnel is a
    // genuine extension and must appear in both ETB and TBB.
    $awExtension = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['aw_extension'],
        'A/W',
        $actor
    );
    $awExtensionId = (int) $awExtension['dienstbesetzung_id'];
    $awExtensionBefore = $logbookEvidenceSnapshot($connection, $incidentId);
    $acceptedAwExtension = estab_dv_accept_hat(
        $connection,
        $incidentId,
        $awExtensionId,
        $codes['aw_extension']
    );
    $assert(
        ($acceptedAwExtension['active_shift_extension'] ?? null) === true
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?'
                    . " AND `etb_aktion` LIKE '%Zusätzliche Aufnahme/Weitergabe%'",
                'i',
                $incidentId
            ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?'
                    . " AND `estab_entry_type` = 'betrieb_personal'"
                    . " AND `estab_personnel_duty`"
                    . " LIKE '%Zusätzliche Aufnahme/Weitergabe%'",
                'i',
                $incidentId
            ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === ((int) explode(':', $awExtensionBefore['etb'], 2)[0]) + 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === ((int) explode(':', $awExtensionBefore['ttb'], 2)[0]) + 1,
        'accepted active A/W extension was not appended atomically to ETB and TBB'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes['aw_extension'],
            'A/W',
            $actor
        ),
        'the same person received the same active A/W function twice'
    );

    $connection->begin_transaction();
    try {
        $expect(
            LogicException::class,
            static fn (): null => (
                estab_dynamic_schema_reconcile_hat(
                    $connection,
                    'usr_',
                    'S3',
                    $codes['s2'],
                    'Stab'
                ) ?? null
            ),
            'dynamic function DDL was allowed inside a domain transaction'
        );
        $assert(
            (int) $scalar(
                $connection,
                'SELECT @@SESSION.in_transaction'
            ) === 1,
            'rejected dynamic DDL implicitly ended the caller transaction'
        );
    } finally {
        $connection->rollback();
    }

    $s3Identity = [
        'benutzer' => 'Sachgebiet 3',
        'kuerzel' => $codes['s3'],
        'funktion' => 'S3',
        'rolle' => 'Stab',
        'duty_assignment_id' => $assignments['s3'],
    ];
    $assert(
        estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $s3Identity
        )['funktion'] === 'S3',
        'fixed S3 account was not accepted at the operational boundary'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            [
                'benutzer' => 'Lage/Dokumentation',
                'kuerzel' => $codes['s2'],
                'funktion' => 'S3',
                'rolle' => 'Stab',
            ]
        ),
        'an S2 account changed function through historical S3 staffing'
    );

    $s3ReadTable = estab_message_state_table(
        'usr_',
        'S3',
        $codes['s3'],
        'read'
    );
    $s3DoneTable = estab_message_state_table(
        'usr_',
        'S3',
        $codes['s3'],
        'done'
    );
    $s3FunctionCategory = 'usr__fkt_s3_katego';
    $s3FunctionLink = 'usr__fkt_s3_kategolink';
    $s3UserCategory = 'usr_s3_' . $codes['s3'] . '_katego';
    $s3UserLink = 'usr_s3_' . $codes['s3'] . '_kategolink';
    $expectedS3Tables = [
        $s3ReadTable,
        $s3DoneTable,
        $s3FunctionCategory,
        $s3FunctionLink,
        $s3UserCategory,
        $s3UserLink,
    ];
    $tablePlaceholders = implode(
        ',',
        array_fill(0, count($expectedS3Tables), '?')
    );
    $tableTypes = str_repeat('s', count($expectedS3Tables));
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE()'
                . ' AND table_type = ? AND table_name IN ('
                . $tablePlaceholders . ')',
            's' . $tableTypes,
            'BASE TABLE',
            ...$expectedS3Tables
        ) === 6,
        'accepted S3 hat did not receive all six legacy state/category tables'
    );

    // Compatibility contract for historical/imported E/8 conversation notes.
    // Newly authored notes use the regular A/4 → Si → LdF → A/W path and gain
    // their TTB evidence only when that outgoing transport is completed.
    $conversationTtbBefore = (string) $scalar(
        $connection,
        "SELECT CONCAT("
            . "(SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(`estab_book_lfd`), 0))"
            . " FROM `nv_tbb` WHERE `einsatz_id` = ?), '|',"
            . "(SELECT `next_lfd` FROM `nv_logbuch_koepfe`"
            . " WHERE `einsatz_id` = ? AND `buchart` = 'TTB'))",
        'ii',
        $incidentId,
        $incidentId
    );
    $conversation = estab_message_insert_numbered(
        $connection,
        $databaseName,
        'nv_nachrichten',
        'E',
        false,
        [
            '01_medium' => '',
            '01_datum' => date('Y-m-d H:i:s'),
            '01_zeichen' => $codes['s3'],
            '10_anschrift' => 'Führungsstelle Integration',
            '11_gesprnotiz' => 't',
            '12_inhalt' => 'S3-Gesprächsnotiz aus festem S3-Konto',
            '12_abfzeit' => date('Y-m-d H:i:s'),
            '14_zeichen' => $codes['s3'],
            '14_funktion' => 'S3',
            '16_empf' => 'S2_rt,S3_gn',
            'x00_status' => 8,
            'x01_abschluss' => 't',
            'x02_sperre' => 'f',
            'x03_sperruser' => '',
        ],
        'nv_anhang',
        [
            'event_type' => 'conversation_note_created',
            'actor' => $s3Identity,
            'from_status' => null,
            'to_status' => 8,
            'snapshot' => [
                'direction' => 'E',
                'object_type' => 'conversation_note',
                'conversation_note' => true,
                'author_code' => $codes['s3'],
                'author_function' => 'S3',
                'review_required' => false,
            ],
        ]
    );
    $conversationMessageId = (int) $conversation['id'];
    $assert(
        $conversationMessageId > 0
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb`'
                    . ' WHERE `einsatz_id` = ? AND `estab_message_id` = ?',
                'ii',
                $incidentId,
                $conversationMessageId
            ) === 0
            && (string) $scalar(
                $connection,
                "SELECT CONCAT("
                    . "(SELECT CONCAT(COUNT(*), ':',"
                    . " COALESCE(MAX(`estab_book_lfd`), 0)) FROM `nv_tbb`"
                    . " WHERE `einsatz_id` = ?), '|',"
                    . "(SELECT `next_lfd` FROM `nv_logbuch_koepfe`"
                    . " WHERE `einsatz_id` = ? AND `buchart` = 'TTB'))",
                'ii',
                $incidentId,
                $incidentId
            ) === $conversationTtbBefore
            && (estab_message_fetch_for_incident_by_id(
                $connection,
                'nv_nachrichten',
                $conversationMessageId,
                $incidentId
            )['estab_ttb_lfd'] ?? null) === null,
        'legacy E/8 conversation note consumed a TTB number or exposed a message TTB reference'
    );
    $stateTimestamp = date('Y-m-d H:i:s');
    $assert(
        estab_message_state_set_for_recipient(
            $connection,
            'nv_nachrichten',
            $s3ReadTable,
            $conversationMessageId,
            $s3Identity,
            'read',
            $stateTimestamp
        ),
        'fixed S3 account could not write its personal read state'
    );
    $assert(
        estab_message_state_set_for_recipient(
            $connection,
            'nv_nachrichten',
            $s3DoneTable,
            $conversationMessageId,
            $s3Identity,
            'done',
            $stateTimestamp
        ),
        'fixed S3 account could not write its function done state'
    );

    $categoryConfig = [
        'usrtblprefix' => 'usr_',
        'masterkatego' => 'nv_masterkatego',
        'masterkategolk' => 'nv_masterkategolink',
    ];
    $functionScope = estab_category_scope(
        'fkt',
        $s3Identity,
        $categoryConfig
    );
    $userScope = estab_category_scope(
        'user',
        $s3Identity,
        $categoryConfig
    );
    $masterScope = estab_category_scope(
        'master',
        $s3Identity,
        $categoryConfig
    );
    $masterCategoryId = estab_category_create(
        $connection,
        $masterScope,
        ['kategorie' => 'M' . $suffix, 'beschreibung' => 'Master-Sperrtest']
    );
    $expect(
        EstabCategoryAuthorizationException::class,
        static fn (): null => (
            estab_category_assign(
                $connection,
                $conversationMessageId,
                'nv_nachrichten',
                $s3Identity,
                ['master' => $masterScope],
                ['master' => $masterCategoryId],
                'nv_empfmtx'
            ) ?? null
        ),
        'composed category API accepted a master assignment without '
            . 'locked Si/redcopy authority'
    );
    $functionCategoryId = estab_category_create(
        $connection,
        $functionScope,
        ['kategorie' => 'F' . $suffix, 'beschreibung' => 'S3-Funktion']
    );
    $userCategoryId = estab_category_create(
        $connection,
        $userScope,
        ['kategorie' => 'U' . $suffix, 'beschreibung' => 'S3-Konto']
    );
    estab_category_assign(
        $connection,
        $conversationMessageId,
        'nv_nachrichten',
        $s3Identity,
        ['fkt' => $functionScope, 'user' => $userScope],
        ['fkt' => $functionCategoryId, 'user' => $userCategoryId]
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM '
                . estab_auth_table($s3FunctionLink)
                . ' WHERE `msg` = ? AND `katego` = ?',
            'ii',
            $conversationMessageId,
            $functionCategoryId
        ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM '
                    . estab_auth_table($s3UserLink)
                    . ' WHERE `msg` = ? AND `katego` = ?',
                'ii',
                $conversationMessageId,
                $userCategoryId
            ) === 1,
        'fixed S3 account could not persist function and user categories'
    );
    $etbIdentity = [
        'benutzer' => 'Einsatztagebuchführung',
        'kuerzel' => $codes['etb'],
        'funktion' => 'ETB',
        'rolle' => 'Stab',
        'duty_assignment_id' => $assignments['etb'],
    ];
    $assert(
        estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $etbIdentity
        )['funktion'] === 'ETB',
        'fixed ETB account was not accepted at the operational boundary'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            [
                'benutzer' => 'Sichter',
                'kuerzel' => $codes['si'],
                'funktion' => 'ETB',
                'rolle' => 'Stab',
            ]
        ),
        'a Si account changed function through historical ETB staffing'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE()'
                . ' AND table_name IN (?, ?, ?, ?, ?, ?)',
            'ssssss',
            'usr_etb_' . $codes['etb'] . '_read',
            'usr__fkt_etb_erl',
            'usr__fkt_etb_katego',
            'usr__fkt_etb_kategolink',
            'usr_etb_' . $codes['etb'] . '_katego',
            'usr_etb_' . $codes['etb'] . '_kategolink'
        ) === 0,
        'fixed ETB account received unrelated message/category tables'
    );

    $awIdentity = [
        'benutzer' => 'Aufnahme/Weitergabe',
        'kuerzel' => $codes['aw'],
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
        'duty_assignment_id' => $assignments['aw'],
    ];
    $messengerIdentity = [
        'benutzer' => 'Melder',
        'kuerzel' => $codes['messenger'],
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
        'duty_assignment_id' => $assignments['messenger'],
    ];
    $s2Identity = [
        'benutzer' => 'Lage/Dokumentation',
        'kuerzel' => $codes['s2'],
        'funktion' => 'S2',
        'rolle' => 'Stab',
        'duty_assignment_id' => $assignments['s2'],
    ];
    $siIdentity = [
        'benutzer' => 'Sichter',
        'kuerzel' => $codes['si'],
        'funktion' => 'Si',
        'rolle' => 'Stab',
        'duty_assignment_id' => $assignments['si'],
    ];
    $s6Identity = [
        'benutzer' => 'Fernmeldeplanung',
        'kuerzel' => $codes['s6'],
        'funktion' => 'S6',
        'rolle' => 'Stab',
        'duty_assignment_id' => $assignments['s6'],
    ];
    $ldfIdentity = [
        'benutzer' => 'Leiter FmZt',
        'kuerzel' => $codes['ldf'],
        'funktion' => 'LdF',
        'rolle' => 'Fernmelder',
        'duty_assignment_id' => $assignments['ldf'],
    ];
    $assert(
        estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $awIdentity
        )['funktion'] === 'A/W',
        'accepted and selected A/W duty assignment was denied in STRICT'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            [
                'benutzer' => 'Zweiter Sichter',
                'kuerzel' => $codes['si_duplicate'],
                'funktion' => 'Si',
                'rolle' => 'Stab',
            ]
        ),
        'STRICT admitted a fixed account without accepted selected staffing'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            array_replace($awIdentity, ['funktion' => 'S2', 'rolle' => 'Stab'])
        ),
        'a forged function/role tuple authorized another capability'
    );
    $listedShifts = estab_dv_shift_list($connection, $incidentId);
    $assert(
        count($listedShifts) === 1
            && (int) $listedShifts[0]['dienstschicht_id'] === $shiftId
            && count($listedShifts[0]['besetzungen']) === 11,
        'real duty-shift UI read model did not return the active staffing'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_logbook_insert_entry(
            $databaseConfig,
            'nv_etb',
            'etb',
            [
                'event' => 'Unzulässiger Sichter-Eintrag',
                'comment' => 'Si ist nicht Lage/Dokumentation.',
                'event_time' => date('Y-m-d H:i:s'),
                'event_type' => 'information',
            ],
            $siIdentity
        ),
        'a fixed Si account wrote directly into the ETB domain'
    );
    $etbEntryId = estab_logbook_insert_entry(
        $databaseConfig,
        'nv_etb',
        'etb',
        [
            'event' => 'Eigenständige ETB-Funktion fachlich bestätigt',
            'comment' => 'Dasselbe Konto trägt getrennte ETB- und Si-Hüte.',
            'event_time' => date('Y-m-d H:i:s'),
            'event_type' => 'information',
        ],
        $etbIdentity
    );
    $assert(
        $etbEntryId > 0,
        'fixed ETB account could not write through EINSATZTAGEBUCH capability'
    );
    $assert(
        !estab_logbook_is_designated_writer(
            $connection,
            $incidentId,
            $s2Identity,
            'etb'
        ),
        'STRICT no longer gives S2 the ETB writer role while the selected '
            . 'ETB assignment has precedence'
    );

    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_create_telecom_plan(
            $connection,
            $incidentId,
            [
                'benutzer' => 'Fernmeldeplanung',
                'kuerzel' => $codes['s6'],
                'funktion' => 'S2',
                'rolle' => 'Stab',
            ],
            [
                'herkunft' => 'Unzulässiger S2-Kontext',
                'gueltig_ab' => date('Y-m-d H:i:s', time() - 60),
                'gueltig_bis' => date('Y-m-d H:i:s', time() + 3600),
                'betriebsleitung' => 'Unzulässig',
                'bemerkungen' => '',
            ]
        ),
        'S6 privilege ignored the account’s fixed function'
    );
    $plan = estab_dv_create_telecom_plan(
        $connection,
        $incidentId,
        $s6Identity,
        [
            'herkunft' => 'S6 Führungsstelle',
            'gueltig_ab' => date('Y-m-d H:i:s', time() - 60),
            'gueltig_bis' => date('Y-m-d H:i:s', time() + 3600),
            'betriebsleitung' => 'LdF ' . $codes['ldf'],
            'bemerkungen' => 'Integrationsplan',
        ]
    );
    $planId = (int) $plan['fernmeldeplan_id'];
    $createdPlan = $telecomPlanById($connection, $incidentId, $planId);
    $createdPlanEvent = $telecomPlanEvent(
        $connection,
        $incidentId,
        $planId,
        'plan_created'
    );
    $assert(
        ($createdPlanEvent['details']['initial_state'] ?? null)
            === estab_dv_telecom_plan_header_audit_state($createdPlan)
            && preg_match(
                '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/D',
                (string) ($createdPlan['erstellt_am'] ?? '')
            ) === 1
            && ($createdPlan['freigegeben_am'] ?? null) === null,
        'new telecommunications plan lacks its exact initial header snapshot'
    );
    $routeId = estab_dv_add_telecom_entry(
        $connection,
        $incidentId,
        $planId,
        $s6Identity,
        [
            'betriebsstelle' => 'Gegenstelle Integration',
            'rufname' => 'Integration 01',
            'medium' => 'Me',
            'kanal' => 'persönlich',
            'bandlage' => 'entfällt',
            'verkehrsform' => 'Melderbeförderung',
            'besondere_vermerke' => 'Identität am Ziel feststellen',
            'bemerkungen' => 'Rückweg über FmZt',
        ]
    );
    $secondaryRouteId = estab_dv_add_telecom_entry(
        $connection,
        $incidentId,
        $planId,
        $s6Identity,
        [
            'betriebsstelle' => 'Funk-Gegenstelle Integration',
            'rufname' => 'Integration Funk 02',
            'medium' => 'Fu',
            'kanal' => 'TMO 402',
            'bandlage' => 'G/U',
            'verkehrsform' => 'Gegenverkehr',
            'besondere_vermerke' => 'Priorisierte Führungsverbindung',
            'bemerkungen' => 'Rückfallebene über Fernsprecher',
        ]
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $planId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_fernmeldeplaene` SET `version` = `version` + 1'
                . ' WHERE `fernmeldeplan_id` = ?'
            );
            try {
                $statement->bind_param('i', $planId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'draft telecommunications plan identity/version was mutable'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $planId, $codes): void {
            $statement = $connection->prepare(
                'UPDATE `nv_fernmeldeplaene`'
                . " SET `status` = 'AKTIV', `freigegeben_am` = NOW(6),"
                . ' `freigegeben_von` = ?'
                . ' WHERE `fernmeldeplan_id` = ?'
            );
            try {
                $statement->bind_param('si', $codes['aw'], $planId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'non-S6 direct SQL release activated a telecommunications plan'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $routeId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_fernmeldeplan_eintraege`'
                . ' SET `fernmeldeplan_eintrag_id` = ?'
                . ' WHERE `fernmeldeplan_eintrag_id` = ?'
            );
            $forgedRouteId = $routeId + 100000;
            try {
                $statement->bind_param('ii', $forgedRouteId, $routeId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'telecommunications route identity was mutable while in draft'
    );
    estab_dv_activate_telecom_plan(
        $connection,
        $incidentId,
        $planId,
        $s6Identity
    );
    $activatedPlan = $telecomPlanById($connection, $incidentId, $planId);
    $route = estab_dv_resolve_active_route(
        $connection,
        $incidentId,
        $routeId,
        'Me'
    );
    $assert(
        (int) $route['fernmeldeplan_eintrag_id'] === $routeId
            && (int) $route['version'] === 1
            && preg_match(
                '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/D',
                (string) ($activatedPlan['freigegeben_am'] ?? '')
            ) === 1,
        'active S6 route was not resolved with its immutable version'
    );

    $insertMessage = $connection->prepare(
        'INSERT INTO `nv_nachrichten`'
        . ' (`einsatz_id`, `04_richtung`, `04_nummer`, `06_befweg`,'
        . ' `01_medium`, `estab_fernmeldeplan_eintrag_id`,'
        . ' `10_anschrift`, `12_inhalt`, `14_zeichen`, `14_funktion`,'
        . ' `x00_status`, `x01_abschluss`)'
        . " VALUES (?, 'A', 1, ?, 'Me', ?, ?, ?, ?, 'LdF', 2, 'f')"
    );
    if (!$insertMessage) {
        throw new RuntimeException('Could not prepare messenger test message');
    }
    try {
        $routeLabel = 'S6/V1 · Integration 01 · persönlich · Me';
        $destination = 'Gegenstelle Integration';
        $content = 'Nachricht zur nachgewiesenen Melderbeförderung';
        $insertMessage->bind_param(
            'iisiss',
            $incidentId,
            $routeLabel,
            $routeId,
            $destination,
            $content,
            $codes['ldf']
        );
        $insertMessage->execute();
        $messageId = (int) $connection->insert_id;
    } finally {
        $insertMessage->close();
    }
    $assert($messageId > 0, 'messenger test message was not created');
    $connection->begin_transaction();
    try {
        estab_message_event_append(
            $connection,
            $incidentId,
            $messageId,
            'dv_operations_fixture_created',
            [
                'benutzer' => 'Leiter FmZt',
                'kuerzel' => $codes['ldf'],
                'funktion' => 'LdF',
            ],
            null,
            2,
            ['fixture' => 'DV messenger transport']
        );
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }

    // LOOSE keeps every Fachzuständigkeit bound to the account's primary
    // function or an explicit personal additional function. Permission mode is
    // immutable once operational records exist, so STRICT and LOOSE use
    // independent incident identities and are activated in turn.
    $permissionModeStatus = estab_incident_status($connection);
    $assert(
        (int) ($permissionModeStatus['active_einsatz_id'] ?? 0) === $incidentId
            && ($permissionModeStatus['estab_permission_mode'] ?? null)
                === 'STRICT',
        'DV permission-mode matrix did not start in STRICT'
    );
    estab_permission_context_set_from_incident($permissionModeStatus);
    $permissionModeEtbBefore = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $permissionModeTtbBefore = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $permissionModeJobsBefore = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_melderauftraege` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $strictPermissionModeAuditBefore = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_einsatz_ereignisse`'
            . ' WHERE `einsatz_id` = ?'
            . " AND `aktion` = 'berechtigung_geaendert'",
        'i',
        $incidentId
    );
    $permissionEtbEntry = static fn (string $probe, array $extra = []): array =>
        $extra + [
            'event' => 'Berechtigungsmodus ETB ' . $probe,
            'comment' => 'MariaDB-Matrix Migration 115',
            'event_time' => date('Y-m-d H:i:s'),
            'event_type' => 'information',
        ];
    $permissionTtbEntry = static fn (string $probe, array $extra = []): array =>
        $extra + [
            'entry_type' => 'betriebsereignis',
            'operations' => 'Berechtigungsmodus TTB ' . $probe,
            'comment' => 'MariaDB-Matrix Migration 115',
        ];

    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_logbook_insert_entry(
            $databaseConfig,
            'nv_etb',
            'etb',
            $permissionEtbEntry('strict-before'),
            $s3Identity
        ),
        'STRICT admitted an S3 account into ETB'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_logbook_insert_entry(
            $databaseConfig,
            'nv_tbb',
            'tbb',
            $permissionTtbEntry('strict-before'),
            $s3Identity
        ),
        'STRICT admitted an S3 account into TTB'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_dv_assign_messenger(
            $connection,
            $incidentId,
            $messageId,
            $codes['messenger'],
            'Gegenstelle Integration',
            $s3Identity
        ),
        'STRICT admitted an S3 account as messenger supervisor'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ) === $permissionModeEtbBefore
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === $permissionModeTtbBefore
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_melderauftraege`'
                    . ' WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === $permissionModeJobsBefore,
        'STRICT cross-role probes left partial ETB, TTB or messenger data'
    );

    $permissionModeStatus = estab_incident_status($connection);
    $loosePermissionCreated = estab_incident_create(
        $connection,
        [
            'kennung' => 'DV-LOOSE-' . strtoupper($suffix),
            'name' => 'DV-Berechtigungsmatrix LOOSE',
            'beginn' => date('Y-m-d\TH:i', time() - 1800),
            'ort' => 'Integrationsprüfung',
            'organisation' => 'THW',
            'fuehrungsstellenname' => 'Führungsstelle DV-Operationen',
            'einsatzleitung' => 'Leitung Integration',
            'beschreibung' =>
                'Isolierter LOOSE-Nachweis expliziter Zusatzfunktionen.',
            'estab_permission_mode' => ESTAB_PERMISSION_MODE_LOOSE,
        ],
        'dv-permission-mode-matrix',
        true,
        (int) $permissionModeStatus['revision'],
        true
    );
    $loosePermissionIncidentId =
        (int) $loosePermissionCreated['einsatz_id'];
    $loosePermissionIncident = estab_incident_status($connection);
    estab_permission_context_set_from_incident($loosePermissionIncident);
    $loosePermissionPlan = estab_dv_create_telecom_plan(
        $connection,
        $loosePermissionIncidentId,
        $s6Identity,
        [
            'herkunft' => 'S6 LOOSE-Matrix',
            'gueltig_ab' => date('Y-m-d H:i:s', time() - 60),
            'gueltig_bis' => date('Y-m-d H:i:s', time() + 3600),
            'betriebsleitung' => 'LdF ' . $codes['ldf'],
            'bemerkungen' => 'Isolierter LOOSE-Fernmeldeplan',
        ]
    );
    $loosePermissionPlanId =
        (int) $loosePermissionPlan['fernmeldeplan_id'];
    $loosePermissionRouteId = estab_dv_add_telecom_entry(
        $connection,
        $loosePermissionIncidentId,
        $loosePermissionPlanId,
        $s6Identity,
        [
            'betriebsstelle' => 'Gegenstelle LOOSE',
            'rufname' => 'Integration LOOSE 01',
            'medium' => 'Me',
            'kanal' => 'persönlich',
            'bandlage' => 'entfällt',
            'verkehrsform' => 'Melderbeförderung',
            'besondere_vermerke' => 'Identität am Ziel feststellen',
            'bemerkungen' => 'LOOSE-Berechtigungsmatrix',
        ]
    );
    estab_dv_activate_telecom_plan(
        $connection,
        $loosePermissionIncidentId,
        $loosePermissionPlanId,
        $s6Identity
    );
    $looseMessageInsert = $connection->prepare(
        'INSERT INTO `nv_nachrichten`'
            . ' (`einsatz_id`, `04_richtung`, `04_nummer`, `06_befweg`,'
            . ' `01_medium`, `estab_fernmeldeplan_eintrag_id`,'
            . ' `10_anschrift`, `12_inhalt`, `14_zeichen`, `14_funktion`,'
            . ' `x00_status`, `x01_abschluss`)'
            . " VALUES (?, 'A', 1, ?, 'Me', ?, ?, ?, ?, 'LdF', 2, 'f')"
    );
    if (!$looseMessageInsert) {
        throw new RuntimeException('Could not prepare LOOSE messenger fixture');
    }
    try {
        $looseRouteLabel = 'S6/V1 · Integration LOOSE 01 · persönlich · Me';
        $looseDestination = 'Gegenstelle LOOSE';
        $looseContent = 'Nachricht für die LOOSE-Berechtigungsmatrix';
        $looseMessageInsert->bind_param(
            'iisiss',
            $loosePermissionIncidentId,
            $looseRouteLabel,
            $loosePermissionRouteId,
            $looseDestination,
            $looseContent,
            $codes['ldf']
        );
        $looseMessageInsert->execute();
        $loosePermissionMessageId = (int) $connection->insert_id;
    } finally {
        $looseMessageInsert->close();
    }
    $looseConversationInsert = $connection->prepare(
        'INSERT INTO `nv_nachrichten`'
            . ' (`einsatz_id`, `04_richtung`, `04_nummer`, `11_gesprnotiz`,'
            . ' `12_inhalt`, `14_zeichen`, `14_funktion`, `x00_status`,'
            . ' `x01_abschluss`)'
            . " VALUES (?, 'E', 2, 't', ?, ?, 'S3', 8, 't')"
    );
    if (!$looseConversationInsert) {
        throw new RuntimeException('Could not prepare LOOSE direction fixture');
    }
    try {
        $looseConversationContent = 'Unzulässige Melder-Richtung in LOOSE';
        $looseConversationInsert->bind_param(
            'iss',
            $loosePermissionIncidentId,
            $looseConversationContent,
            $codes['s3']
        );
        $looseConversationInsert->execute();
        $loosePermissionConversationMessageId =
            (int) $connection->insert_id;
    } finally {
        $looseConversationInsert->close();
    }
    $connection->begin_transaction();
    try {
        foreach ([
            [$loosePermissionMessageId, 'A', 2],
            [$loosePermissionConversationMessageId, 'E', 8],
        ] as [$fixtureMessageId, $fixtureDirection, $fixtureStatus]) {
            estab_message_event_append(
                $connection,
                $loosePermissionIncidentId,
                $fixtureMessageId,
                'dv_permission_fixture_created',
                [
                    'benutzer' => 'DV LOOSE Fixture',
                    'kuerzel' => $codes['s6'],
                    'funktion' => 'S6',
                ],
                null,
                $fixtureStatus,
                ['direction' => $fixtureDirection]
            );
        }
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    $permissionModeEtbBefore = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
        'i',
        $loosePermissionIncidentId
    );
    $permissionModeTtbBefore = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
        'i',
        $loosePermissionIncidentId
    );
    $permissionModeJobsBefore = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_melderauftraege` WHERE `einsatz_id` = ?',
        'i',
        $loosePermissionIncidentId
    );
    $loosePermissionModeAuditBefore = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_einsatz_ereignisse`'
            . ' WHERE `einsatz_id` = ?'
            . " AND `aktion` = 'berechtigung_geaendert'",
        'i',
        $loosePermissionIncidentId
    );
    $strictPermissionIncident = null;
    $looseModeJobId = 0;
    try {
        $setLoosePrimaryFunction = $connection->prepare(
            'UPDATE `nv_benutzer` SET `funktion` = ?, `rolle` = ?'
                . ' WHERE BINARY `kuerzel` = BINARY ?'
        );
        if (!$setLoosePrimaryFunction) {
            throw new RuntimeException(
                'Could not prepare stale loose primary-function probe'
            );
        }
        try {
            $primaryCode = $s3Identity['kuerzel'];
            $stalePrimaryFunction = 'ZZ';
            $stalePrimaryRole = 'Stab';
            $setLoosePrimaryFunction->bind_param(
                'sss',
                $stalePrimaryFunction,
                $stalePrimaryRole,
                $primaryCode
            );
            $setLoosePrimaryFunction->execute();
            $stalePrimaryIdentity = $s3Identity;
            $stalePrimaryIdentity['funktion'] = $stalePrimaryFunction;
            $stalePrimaryIdentity['rolle'] = $stalePrimaryRole;
            $expect(
                EstabDvPermissionException::class,
                static fn (): array => estab_dv_require_operational_account(
                    $connection,
                    $loosePermissionIncidentId,
                    $stalePrimaryIdentity,
                    false
                ),
                'LOOSE accepted a stale primary account function outside '
                    . 'the authoritative catalogue'
            );
        } finally {
            $restoredPrimaryFunction = 'S3';
            $restoredPrimaryRole = 'Stab';
            $setLoosePrimaryFunction->bind_param(
                'sss',
                $restoredPrimaryFunction,
                $restoredPrimaryRole,
                $primaryCode
            );
            $setLoosePrimaryFunction->execute();
            $setLoosePrimaryFunction->close();
        }

        $expect(
            EstabDvPermissionException::class,
            static fn (): int => estab_logbook_insert_entry(
                $databaseConfig,
                'nv_etb',
                'etb',
                $permissionEtbEntry('loose-without-grant'),
                $s3Identity
            ),
            'LOOSE admitted S3 into ETB without an explicit ETB grant'
        );
        $expect(
            EstabDvPermissionException::class,
            static fn (): int => estab_logbook_insert_entry(
                $databaseConfig,
                'nv_tbb',
                'tbb',
                $permissionTtbEntry('loose-without-grant'),
                $s3Identity
            ),
            'LOOSE admitted S3 into TTB without an explicit A/W grant'
        );
        $expect(
            EstabDvPermissionException::class,
            static fn (): int => estab_dv_assign_messenger(
                $connection,
                $loosePermissionIncidentId,
                $loosePermissionMessageId,
                $codes['messenger'],
                'Gegenstelle Integration',
                $s3Identity
            ),
            'LOOSE admitted S3 as messenger supervisor without an LdF grant'
        );
        $expect(
            EstabDvPermissionException::class,
            static fn (): array => estab_dv_start_telecom_plan_revision(
                $connection,
                $loosePermissionIncidentId,
                $loosePermissionPlanId,
                $s3Identity
            ),
            'LOOSE admitted S3 into S6 planning without an explicit S6 grant'
        );
        $assert(
            (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
                'i',
                $loosePermissionIncidentId
            ) === $permissionModeEtbBefore
                && (int) $scalar(
                    $connection,
                    'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
                    'i',
                    $loosePermissionIncidentId
                ) === $permissionModeTtbBefore
                && (int) $scalar(
                    $connection,
                    'SELECT COUNT(*) FROM `nv_melderauftraege`'
                        . ' WHERE `einsatz_id` = ?',
                    'i',
                    $loosePermissionIncidentId
                ) === $permissionModeJobsBefore,
            'LOOSE ungranted probes left partial operational records'
        );

        $grantAdditionalFunction = $connection->prepare(
            'INSERT INTO `nv_benutzer_zusatzfunktionen`'
                . ' (`benutzer_kuerzel`, `funktion`, `rolle`, `vergeben_von`)'
                . ' VALUES (?, ?, ?, ?)'
        );
        if (!$grantAdditionalFunction) {
            throw new RuntimeException(
                'Could not prepare loose additional-function grants'
            );
        }
        try {
            $grantCode = $s3Identity['kuerzel'];
            $grantActor = 'dv-permission-mode-matrix';
            foreach ([
                ['ETB', 'Stab'],
                ['A/W', 'Fernmelder'],
                ['LdF', 'Fernmelder'],
                ['S6', 'Stab'],
            ] as [$function, $role]) {
                $grantAdditionalFunction->bind_param(
                    'ssss',
                    $grantCode,
                    $function,
                    $role,
                    $grantActor
                );
                $grantAdditionalFunction->execute();
            }
        } finally {
            $grantAdditionalFunction->close();
        }

        $looseS3Authority = estab_dv_require_operational_account(
            $connection,
            $loosePermissionIncidentId,
            $s3Identity,
            false
        );
        $looseS6Authority = estab_workflow_identity_as_tuple(
            $looseS3Authority,
            ['funktion' => 'S6', 'rolle' => 'Stab']
        );
        $looseAttachmentContext = estab_attachment_origin_context_create(
            $looseS6Authority,
            $loosePermissionIncidentId,
            ['task' => 'Stab_schreiben', '00_lfd' => '']
        );
        $assert(
            ($looseAttachmentContext['permission_mode'] ?? null) === 'LOOSE'
                && array_key_exists(
                    'duty_assignment_id',
                    $looseAttachmentContext
                )
                && $looseAttachmentContext['duty_assignment_id'] === null
                && ($looseAttachmentContext['account_function'] ?? null)
                    === 'S3'
                && ($looseAttachmentContext['account_role'] ?? null)
                    === 'Stab'
                && ($looseAttachmentContext['funktion'] ?? null) === 'S6'
                && ($looseAttachmentContext['rolle'] ?? null) === 'Stab'
                && ($looseAttachmentContext['function_source'] ?? null)
                    === 'ADDITIONAL'
                && estab_attachment_origin_context_validate(
                    $looseAttachmentContext,
                    $looseS3Authority,
                    $loosePermissionIncidentId
                ) === $looseAttachmentContext,
            'LOOSE attachment origin lost its exact canonical additional '
                . 'function and fixed-account provenance'
        );

        $setLooseMessengerSignedOut = $connection->prepare(
            'UPDATE `nv_benutzer` SET `aktiv` = 0, `sid` = ?, '
                . '`estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
                . ' WHERE BINARY `kuerzel` = BINARY ?'
        );
        if (!$setLooseMessengerSignedOut) {
            throw new RuntimeException(
                'Could not prepare LOOSE messenger presence probe'
            );
        }
        try {
            $emptySessionId = '';
            $setLooseMessengerSignedOut->bind_param(
                'ss',
                $emptySessionId,
                $codes['messenger']
            );
            $setLooseMessengerSignedOut->execute();
        } finally {
            $setLooseMessengerSignedOut->close();
        }
        $setLooseMessengerInactive = $connection->prepare(
            'UPDATE `nv_benutzer` SET `aktiv` = 1,'
                . ' `estab_letzte_aktivitaet` ='
                . ' UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE'
                . ' WHERE BINARY `kuerzel` = BINARY ?'
        );
        if (!$setLooseMessengerInactive) {
            throw new RuntimeException(
                'Could not prepare LOOSE inactive messenger probe'
            );
        }
        try {
            $setLooseMessengerInactive->bind_param(
                's',
                $codes['aw_extension']
            );
            $setLooseMessengerInactive->execute();
        } finally {
            $setLooseMessengerInactive->close();
        }
        $looseMessengerCandidates = estab_dv_messenger_candidates(
            $connection,
            $loosePermissionIncidentId
        );
        $looseCandidateCodes = array_column(
            $looseMessengerCandidates,
            'kuerzel'
        );
        sort($looseCandidateCodes);
        $expectedLooseCandidateCodes = [
            $codes['aw'],
            $codes['messenger'],
            $codes['aw_extension'],
            $s3Identity['kuerzel'],
        ];
        sort($expectedLooseCandidateCodes);
        $looseCandidatesByCode = array_column(
            $looseMessengerCandidates,
            null,
            'kuerzel'
        );
        $assert(
            $looseCandidateCodes === $expectedLooseCandidateCodes
                && !in_array(
                    $codes['ldf_duplicate'],
                    $looseCandidateCodes,
                    true
                )
                && array_filter(
                    $looseMessengerCandidates,
                    static fn (array $candidate): bool =>
                        $candidate['dienstbesetzung_id'] !== null
                ) === []
                && ($looseCandidatesByCode[$codes['messenger']]
                    ['presence_state'] ?? null) === 'signed_out'
                && ($looseCandidatesByCode[$codes['messenger']]
                    ['requires_separate_notification'] ?? null) === true
                && ($looseCandidatesByCode[$codes['aw_extension']]
                    ['presence_state'] ?? null) === 'inactive'
                && !array_key_exists(
                    'sid',
                    $looseCandidatesByCode[$codes['messenger']] ?? []
                )
                && !array_key_exists(
                    'estab_letzte_aktivitaet',
                    $looseCandidatesByCode[$codes['messenger']] ?? []
                ),
            'LOOSE messenger candidates did not retain fachliche authority '
                . 'while reducing inactive and signed-out presence without SID'
        );

        $looseEtbId = estab_logbook_insert_entry(
            $databaseConfig,
            'nv_etb',
            'etb',
            $permissionEtbEntry('loose-success'),
            $s3Identity
        );
        $looseTtbId = estab_logbook_insert_entry(
            $databaseConfig,
            'nv_tbb',
            'tbb',
            $permissionTtbEntry('loose-success'),
            $s3Identity
        );
        $looseAssignmentDetails = null;
        $looseModeJobId = estab_dv_assign_messenger(
            $connection,
            $loosePermissionIncidentId,
            $loosePermissionMessageId,
            $codes['messenger'],
            'Gegenstelle Integration',
            $s3Identity,
            'nv_protokoll',
            $looseAssignmentDetails
        );
        $expect(
            EstabDvPermissionException::class,
            static fn (): string => estab_dv_transition_messenger(
                $connection,
                $loosePermissionIncidentId,
                $looseModeJobId,
                'accept',
                $messengerIdentity
            ),
            'signed-out messenger accepted a job without authenticating'
        );
        $assert(
            (string) $scalar(
                $connection,
                'SELECT `status` FROM `nv_melderauftraege`'
                    . ' WHERE `einsatz_id` = ? AND `melderauftrag_id` = ?',
                'ii',
                $loosePermissionIncidentId,
                $looseModeJobId
            ) === 'BEAUFTRAGT',
            'rejected signed-out acceptance changed the messenger job'
        );
        $restoreLooseMessengerSession = $connection->prepare(
            'UPDATE `nv_benutzer` SET `aktiv` = 1, `sid` = ?,'
                . ' `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
                . ' WHERE BINARY `kuerzel` = BINARY ?'
        );
        if (!$restoreLooseMessengerSession) {
            throw new RuntimeException(
                'Could not prepare LOOSE messenger presence restore'
            );
        }
        try {
            $restoredMessengerSession =
                'dv-session-' . $suffix . '-' . $codes['messenger'];
            $restoreLooseMessengerSession->bind_param(
                'ss',
                $restoredMessengerSession,
                $codes['messenger']
            );
            $restoreLooseMessengerSession->execute();
            $restoredExtensionSession =
                'dv-session-' . $suffix . '-' . $codes['aw_extension'];
            $restoreLooseMessengerSession->bind_param(
                'ss',
                $restoredExtensionSession,
                $codes['aw_extension']
            );
            $restoreLooseMessengerSession->execute();
        } finally {
            $restoreLooseMessengerSession->close();
        }
        $loosePlanRevision = estab_dv_start_telecom_plan_revision(
            $connection,
            $loosePermissionIncidentId,
            $loosePermissionPlanId,
            $s3Identity
        );
        $loosePlanId = (int) $loosePlanRevision['fernmeldeplan_id'];
        $loosePlan = $telecomPlanById(
            $connection,
            $loosePermissionIncidentId,
            $loosePlanId
        );
        estab_dv_discard_telecom_plan_draft(
            $connection,
            $loosePermissionIncidentId,
            $loosePlanId,
            $s3Identity,
            (string) $loosePlan['revision']
        );
        $assert(
            (string) $scalar(
                $connection,
                'SELECT CONCAT(COUNT(*), ?, COUNT(DISTINCT `akteur_funktion`),'
                    . ' ?, MIN(`akteur_funktion`), ?, MAX(`akteur_funktion`))'
                    . ' FROM `nv_betriebsereignisse`'
                    . ' WHERE `einsatz_id` = ?'
                    . " AND `objekttyp` = 'FERNMELDEPLAN'"
                    . ' AND `objekt_id` = ?'
                    . " AND `aktion` IN ('plan_revision_started',"
                    . " 'plan_draft_discarded')"
                    . ' AND BINARY `akteur_kuerzel` = BINARY ?'
                    . ' AND BINARY JSON_UNQUOTE(JSON_EXTRACT('
                    . " `details`, '$.actor_function')) = BINARY ?",
                'sssiiss',
                '|',
                '|',
                '|',
                $loosePermissionIncidentId,
                $loosePlanId,
                $s3Identity['kuerzel'],
                'S6'
            ) === '2|1|S6|S6',
            'LOOSE telecommunications audit did not retain the authorizing S6 grant'
        );
        $assert(
            $looseEtbId > 0
                && $looseTtbId > 0
                && $looseModeJobId > 0
                && (string) $scalar(
                    $connection,
                    'SELECT CONCAT(`etb_benutzer`, ?, `etb_kuerzel`, ?, '
                        . '`etb_funktion`) FROM `nv_etb`'
                        . ' WHERE `etb_lfd-nr` = ?',
                    'ssi',
                    '|',
                    '|',
                    $looseEtbId
                ) === $s3Identity['benutzer'] . '|'
                    . $s3Identity['kuerzel'] . '|'
                    . 'ETB'
                && (string) $scalar(
                    $connection,
                    'SELECT CONCAT(`tbb_benutzer`, ?, `tbb_kuerzel`, ?, '
                        . '`tbb_funktion`) FROM `nv_tbb`'
                        . ' WHERE `tbb_lfd-nr` = ?',
                    'ssi',
                    '|',
                    '|',
                    $looseTtbId
                ) === $s3Identity['benutzer'] . '|'
                    . $s3Identity['kuerzel'] . '|'
                    . 'A/W'
                && (string) $scalar(
                    $connection,
                    'SELECT CONCAT(job.`beauftragt_von`, ?, supervisor.`benutzer`,'
                        . ' ?, supervisor.`funktion`, ?, supervisor.`rolle`, ?,'
                        . ' job.`melder_kuerzel`, ?, job.`status`)'
                        . ' FROM `nv_melderauftraege` AS job'
                        . ' JOIN `nv_benutzer` AS supervisor'
                        . ' ON BINARY supervisor.`kuerzel` ='
                        . ' BINARY job.`beauftragt_von`'
                        . ' WHERE job.`melderauftrag_id` = ?',
                    'sssssi',
                    '|',
                    '|',
                    '|',
                    '|',
                    '|',
                    $looseModeJobId
                ) === $s3Identity['kuerzel'] . '|'
                    . $s3Identity['benutzer'] . '|'
                    . $s3Identity['funktion'] . '|'
                    . $s3Identity['rolle'] . '|'
                    . $codes['messenger'] . '|BEAUFTRAGT',
            'LOOSE did not persist the account and exact ETB/A-W functions'
        );
        $assert(
            (string) $scalar(
                $connection,
                'SELECT CONCAT(`akteur_kuerzel`, ?, `akteur_funktion`, ?, '
                    . "JSON_UNQUOTE(JSON_EXTRACT(`details`, '$.actor_role')))"
                    . ' FROM `nv_betriebsereignisse`'
                    . ' WHERE `einsatz_id` = ?'
                    . " AND `objekttyp` = 'MELDERAUFTRAG'"
                    . ' AND `objekt_id` = ?'
                    . " AND `aktion` = 'messenger_assigned'",
                'ssii',
                '|',
                '|',
                $loosePermissionIncidentId,
                $looseModeJobId
            ) === $s3Identity['kuerzel'] . '|LdF|Fernmelder',
            'LOOSE messenger assignment lost the authorizing LdF function and role'
        );
        $assert(
            (string) $scalar(
                $connection,
                'SELECT CONCAT('
                    . "JSON_UNQUOTE(JSON_EXTRACT(`details`, '$.permission_mode')) ,"
                    . ' ?, '
                    . "JSON_UNQUOTE(JSON_EXTRACT(`details`, '$.messenger_function')) ,"
                    . ' ?, '
                    . "JSON_UNQUOTE(JSON_EXTRACT(`details`, '$.messenger_role')) ,"
                    . ' ?, '
                    . "JSON_TYPE(JSON_EXTRACT(`details`, '$.messenger_duty_assignment_id')))"
                    . ' FROM `nv_betriebsereignisse`'
                    . ' WHERE `einsatz_id` = ?'
                    . " AND `objekttyp` = 'MELDERAUFTRAG'"
                    . ' AND `objekt_id` = ?'
                    . " AND `aktion` = 'messenger_assigned'",
                'sssii',
                '|',
                '|',
                '|',
                $loosePermissionIncidentId,
                $looseModeJobId
            ) === 'LOOSE|A/W|Fernmelder|NULL',
            'LOOSE messenger assignment lost target function provenance'
        );
        $assert(
            is_array($looseAssignmentDetails)
                && ($looseAssignmentDetails['job_id'] ?? null)
                    === $looseModeJobId
                && ($looseAssignmentDetails['presence_state'] ?? null)
                    === 'signed_out'
                && ($looseAssignmentDetails[
                    'requires_separate_notification'
                ] ?? null) === true
                && (string) $scalar(
                    $connection,
                    'SELECT CONCAT('
                        . "JSON_UNQUOTE(JSON_EXTRACT(`details`,"
                        . " '$.messenger_presence_state')), ?,"
                        . " JSON_UNQUOTE(JSON_EXTRACT(`details`,"
                        . " '$.separate_notification_required')))"
                        . ' FROM `nv_betriebsereignisse`'
                        . ' WHERE `einsatz_id` = ?'
                        . " AND `objekttyp` = 'MELDERAUFTRAG'"
                        . ' AND `objekt_id` = ?'
                        . " AND `aktion` = 'messenger_assigned'",
                    'sii',
                    '|',
                    $loosePermissionIncidentId,
                    $looseModeJobId
                ) === 'signed_out|true',
            'LOOSE messenger assignment did not preserve presence and the '
                . 'separate-notification duty in API result and audit'
        );
        $assert(
            estab_dv_transition_messenger(
                $connection,
                $loosePermissionIncidentId,
                $looseModeJobId,
                'cancel',
                $s3Identity,
                ['abbruchgrund' => 'LOOSE-Matrix abgeschlossen']
            ) === 'ABGEBROCHEN',
            'LOOSE supervisor with explicit LdF grant could not close its test assignment'
        );
        $assert(
            (string) $scalar(
                $connection,
                'SELECT CONCAT(`akteur_kuerzel`, ?, `akteur_funktion`, ?, '
                    . "JSON_UNQUOTE(JSON_EXTRACT(`details`, '$.actor_role')))"
                    . ' FROM `nv_betriebsereignisse`'
                    . ' WHERE `einsatz_id` = ?'
                    . " AND `objekttyp` = 'MELDERAUFTRAG'"
                    . ' AND `objekt_id` = ?'
                    . " AND `aktion` = 'messenger_cancel'",
                'ssii',
                '|',
                '|',
                $loosePermissionIncidentId,
                $looseModeJobId
            ) === $s3Identity['kuerzel'] . '|LdF|Fernmelder',
            'LOOSE messenger transition lost the authorizing LdF function and role'
        );

        $expect(
            EstabIncidentConflictException::class,
            static fn (): int => estab_logbook_insert_entry(
                $databaseConfig,
                'nv_etb',
                'etb',
                $permissionEtbEntry(
                    'missing-reference',
                    ['reference' => '999999999']
                ),
                $s3Identity
            ),
            'LOOSE bypassed the incident-local ETB reference boundary'
        );
        $expect(
            InvalidArgumentException::class,
            static fn (): int => estab_logbook_insert_entry(
                $databaseConfig,
                'nv_tbb',
                'tbb',
                $permissionTtbEntry(
                    'manual-message-reference',
                    ['message_id' => $loosePermissionMessageId]
                ),
                $s3Identity
            ),
            'LOOSE admitted a manually forged TTB message reference'
        );
        $expect(
            EstabDvConflictException::class,
            static fn (): int => estab_dv_assign_messenger(
                $connection,
                $loosePermissionIncidentId,
                $loosePermissionConversationMessageId,
                $codes['messenger'],
                'Gegenstelle Integration',
                $s3Identity
            ),
            'LOOSE bypassed messenger direction, medium or status requirements'
        );
        $expect(
            EstabDvConflictException::class,
            static fn (): int => estab_dv_assign_messenger(
                $connection,
                $loosePermissionIncidentId + 1000000,
                $loosePermissionMessageId,
                $codes['messenger'],
                'Gegenstelle Integration',
                $s3Identity
            ),
            'LOOSE admitted a messenger write for another incident'
        );

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $readAuthorityConnection = estab_auth_connect($databaseConfig);
        $authorityMutationConnection = estab_auth_connect($databaseConfig);
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $readAuthorityTransaction = false;
        try {
            $authorityMutationConnection->query(
                'SET SESSION innodb_lock_wait_timeout = 1'
            );

            $readAuthorityConnection->begin_transaction();
            $readAuthorityTransaction = true;
            $grantReadScope = estab_read_require_operational_scope(
                $readAuthorityConnection,
                $looseS6Authority,
                true
            );
            $assert(
                (int) $scalar(
                    $readAuthorityConnection,
                    'SELECT COUNT(*) FROM `nv_benutzer_zusatzfunktionen`'
                        . ' WHERE BINARY `benutzer_kuerzel` = BINARY ?'
                        . " AND `funktion` = 'S6' AND `rolle` = 'Stab'",
                    's',
                    $s3Identity['kuerzel']
                ) === 1,
                'LOOSE protected read did not resolve the persisted S6 grant'
            );
            estab_read_require_capability(
                $readAuthorityConnection,
                $loosePermissionIncidentId,
                $grantReadScope['identity'],
                'FERNMELDEPLANUNG'
            );
            $grantProtectedPlan = $scalar(
                $readAuthorityConnection,
                'SELECT `fernmeldeplan_id` FROM `nv_fernmeldeplaene`'
                    . ' WHERE `einsatz_id` = ? AND `fernmeldeplan_id` = ?',
                'ii',
                $loosePermissionIncidentId,
                $loosePermissionPlanId
            );
            $expectLockWaitTimeout(
                static function () use (
                    $authorityMutationConnection,
                    $s3Identity
                ): void {
                    $statement = $authorityMutationConnection->prepare(
                        'DELETE FROM `nv_benutzer_zusatzfunktionen`'
                            . ' WHERE BINARY `benutzer_kuerzel` = BINARY ?'
                            . " AND `funktion` = 'S6' AND `rolle` = 'Stab'"
                    );
                    try {
                        $statement->bind_param('s', $s3Identity['kuerzel']);
                        $statement->execute();
                        if ($statement->affected_rows !== 1) {
                            throw new RuntimeException(
                                'LOOSE grant revoke found no persisted S6 grant'
                            );
                        }
                    } finally {
                        $statement->close();
                    }
                },
                'LOOSE grant revoke passed an in-flight protected read'
            );
            $assert(
                (int) $grantProtectedPlan === $loosePermissionPlanId,
                'LOOSE grant-protected object was not selected before release'
            );
            $readAuthorityConnection->commit();
            $readAuthorityTransaction = false;

            $deleteGrant = $authorityMutationConnection->prepare(
                'DELETE FROM `nv_benutzer_zusatzfunktionen`'
                    . ' WHERE BINARY `benutzer_kuerzel` = BINARY ?'
                    . " AND `funktion` = 'S6' AND `rolle` = 'Stab'"
            );
            try {
                $deleteGrant->bind_param('s', $s3Identity['kuerzel']);
                $deleteGrant->execute();
                $assert(
                    $deleteGrant->affected_rows === 1,
                    'LOOSE grant revoke did not complete after read release'
                );
            } finally {
                $deleteGrant->close();
            }
            $expect(
                EstabReadPermissionException::class,
                static fn (): array => estab_read_require_operational_scope(
                    $readAuthorityConnection,
                    $looseS6Authority
                ),
                'LOOSE retained a selected additional function after revoke'
            );
            $restoreGrant = $authorityMutationConnection->prepare(
                'INSERT INTO `nv_benutzer_zusatzfunktionen`'
                    . ' (`benutzer_kuerzel`, `funktion`, `rolle`,'
                    . ' `vergeben_von`) VALUES (?, \'S6\', \'Stab\', ?)'
            );
            try {
                $grantRestoreActor = 'dv-read-authority-race';
                $restoreGrant->bind_param(
                    'ss',
                    $s3Identity['kuerzel'],
                    $grantRestoreActor
                );
                $restoreGrant->execute();
            } finally {
                $restoreGrant->close();
            }

            $insertAccessShift = $authorityMutationConnection->prepare(
                'INSERT INTO `nv_zugangsschichten`'
                    . ' (`einsatz_id`, `bezeichnung`, `zugang_aktiv`,'
                    . ' `erstellt_von`, `geaendert_von`)'
                    . ' VALUES (?, ?, 1, ?, ?)'
            );
            try {
                $accessShiftLabel = 'DV Lesesperre ' . $suffix;
                $accessShiftActor = 'dv-read-authority-race';
                $insertAccessShift->bind_param(
                    'isss',
                    $loosePermissionIncidentId,
                    $accessShiftLabel,
                    $accessShiftActor,
                    $accessShiftActor
                );
                $insertAccessShift->execute();
                $readAccessShiftId = (int) (
                    $authorityMutationConnection->insert_id
                );
            } finally {
                $insertAccessShift->close();
            }
            $insertAccessMember = $authorityMutationConnection->prepare(
                'INSERT INTO `nv_zugangsschicht_mitglieder`'
                    . ' (`zugangsschicht_id`, `benutzer_kuerzel`,'
                    . ' `zugeordnet_von`) VALUES (?, ?, ?)'
            );
            try {
                $insertAccessMember->bind_param(
                    'iss',
                    $readAccessShiftId,
                    $s3Identity['kuerzel'],
                    $accessShiftActor
                );
                $insertAccessMember->execute();
            } finally {
                $insertAccessMember->close();
            }

            $readAuthorityConnection->begin_transaction();
            $readAuthorityTransaction = true;
            $accessReadScope = estab_read_require_operational_scope(
                $readAuthorityConnection,
                $s3Identity,
                true
            );
            $accessProtectedMessage = $scalar(
                $readAuthorityConnection,
                'SELECT `00_lfd` FROM `nv_nachrichten`'
                    . ' WHERE `einsatz_id` = ? AND `00_lfd` = ?',
                'ii',
                $loosePermissionIncidentId,
                $loosePermissionConversationMessageId
            );
            $expectLockWaitTimeout(
                static function () use (
                    $authorityMutationConnection,
                    $readAccessShiftId
                ): void {
                    $statement = $authorityMutationConnection->prepare(
                        'UPDATE `nv_zugangsschichten` SET `zugang_aktiv` = 0'
                            . ' WHERE `zugangsschicht_id` = ?'
                    );
                    try {
                        $statement->bind_param('i', $readAccessShiftId);
                        $statement->execute();
                    } finally {
                        $statement->close();
                    }
                },
                'LOOSE access disable passed an in-flight protected read'
            );
            $assert(
                (int) $accessProtectedMessage
                    === $loosePermissionConversationMessageId
                    && ($accessReadScope['identity']['kuerzel'] ?? null)
                        === $s3Identity['kuerzel'],
                'LOOSE access-protected object was not selected before release'
            );
            $readAuthorityConnection->commit();
            $readAuthorityTransaction = false;

            $disableAccess = $authorityMutationConnection->prepare(
                'UPDATE `nv_zugangsschichten` SET `zugang_aktiv` = 0'
                    . ' WHERE `zugangsschicht_id` = ?'
            );
            try {
                $disableAccess->bind_param('i', $readAccessShiftId);
                $disableAccess->execute();
                $assert(
                    $disableAccess->affected_rows === 1,
                    'LOOSE access disable did not complete after read release'
                );
            } finally {
                $disableAccess->close();
            }
            $disabledMessengerCandidates = estab_dv_messenger_candidates(
                $connection,
                $loosePermissionIncidentId
            );
            $assert(
                !in_array(
                    $s3Identity['kuerzel'],
                    array_column($disabledMessengerCandidates, 'kuerzel'),
                    true
                ),
                'LOOSE messenger candidates ignored a disabled access shift'
            );
            $expect(
                EstabDvConflictException::class,
                static fn (): array => estab_dv_require_messenger_target(
                    $connection,
                    $loosePermissionIncidentId,
                    $s3Identity['kuerzel'],
                    $loosePermissionIncident
                ),
                'LOOSE messenger target ignored a disabled access shift'
            );
            $expect(
                EstabReadPermissionException::class,
                static fn (): array => estab_read_require_operational_scope(
                    $readAuthorityConnection,
                    $s3Identity
                ),
                'LOOSE retained read authority after access disable'
            );
            $enableAccess = $authorityMutationConnection->prepare(
                'UPDATE `nv_zugangsschichten` SET `zugang_aktiv` = 1'
                    . ' WHERE `zugangsschicht_id` = ?'
            );
            try {
                $enableAccess->bind_param('i', $readAccessShiftId);
                $enableAccess->execute();
            } finally {
                $enableAccess->close();
            }
        } finally {
            if ($readAuthorityTransaction) {
                $readAuthorityConnection->rollback();
            }
            estab_auth_close($authorityMutationConnection);
            estab_auth_close($readAuthorityConnection);
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $looseDirectEtbIdentity = $s3Identity;
        $looseDirectEtbIdentity['funktion'] = 'ETB';
        $looseDirectEtbIdentity['rolle'] = 'Stab';
        $looseDirectTtbIdentity = $s3Identity;
        $looseDirectTtbIdentity['funktion'] = 'A/W';
        $looseDirectTtbIdentity['rolle'] = 'Fernmelder';
        $directEtbInsert = static function (
            int $targetIncidentId,
            ?string $reference
        ) use ($connection, $looseDirectEtbIdentity): void {
            $statement = $connection->prepare(
                'INSERT INTO `nv_etb` (`einsatz_id`, `etb_time`,'
                    . ' `etb_aktion`, `etb_bemerk`, `etb_benutzer`,'
                    . ' `etb_kuerzel`, `etb_funktion`, `estab_event_time`,'
                    . ' `estab_event_type`, `estab_reference`)'
                    . " VALUES (?, NOW(6), ?, ?, ?, ?, ?, NOW(6), 'ohne', ?)"
            );
            try {
                $event = 'Direkter ETB-Grenztest';
                $comment = 'Migration 115';
                $statement->bind_param(
                    'issssss',
                    $targetIncidentId,
                    $event,
                    $comment,
                    $looseDirectEtbIdentity['benutzer'],
                    $looseDirectEtbIdentity['kuerzel'],
                    $looseDirectEtbIdentity['funktion'],
                    $reference
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        };
        $directTtbInsert = static function (
            int $targetIncidentId,
            int $targetMessageId
        ) use ($connection, $looseDirectTtbIdentity): void {
            $statement = $connection->prepare(
                'INSERT INTO `nv_tbb` (`einsatz_id`, `tbb_time`,'
                    . ' `tbb_aktion`, `tbb_bemerk`, `tbb_benutzer`,'
                    . ' `tbb_kuerzel`, `tbb_funktion`, `estab_event_time`,'
                    . ' `estab_entry_type`, `estab_message_id`,'
                    . ' `estab_operations`)'
                    . " VALUES (?, NOW(6), ?, ?, ?, ?, ?, NOW(6),"
                    . " 'nachricht', ?, ?)"
            );
            try {
                $event = 'Direkter TTB-Grenztest';
                $comment = 'Migration 115';
                $operations = 'Unzulässiger manueller Nachrichtenbezug';
                $statement->bind_param(
                    'isssssis',
                    $targetIncidentId,
                    $event,
                    $comment,
                    $looseDirectTtbIdentity['benutzer'],
                    $looseDirectTtbIdentity['kuerzel'],
                    $looseDirectTtbIdentity['funktion'],
                    $targetMessageId,
                    $operations
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        };
        $invalidEtbReference = $expect(
            mysqli_sql_exception::class,
            static fn (): null => ($directEtbInsert(
                $loosePermissionIncidentId,
                '999999999'
            ) ?? null),
            'LOOSE ETB trigger accepted a nonexistent local reference'
        );
        $assert(
            str_contains(
                $invalidEtbReference->getMessage(),
                'ETB reference target is not an earlier incident entry'
            ),
            'LOOSE ETB reference probe failed before the intended boundary'
        );
        $wrongEtbIncident = $expect(
            mysqli_sql_exception::class,
            static fn (): null => ($directEtbInsert(
                $loosePermissionIncidentId + 1000000,
                null
            ) ?? null),
            'LOOSE ETB trigger accepted another incident'
        );
        $assert(
            str_contains(
                $wrongEtbIncident->getMessage(),
                'Operational insert targets inactive incident'
            ),
            'LOOSE ETB incident probe failed before the intended boundary'
        );
        $manualTtbReference = $expect(
            mysqli_sql_exception::class,
            static fn (): null => ($directTtbInsert(
                $loosePermissionIncidentId,
                $loosePermissionMessageId
            ) ?? null),
            'LOOSE TTB trigger accepted a human-authored message reference'
        );
        $assert(
            str_contains(
                $manualTtbReference->getMessage(),
                'TTB message link requires system-generated evidence'
            ),
            'LOOSE TTB message probe failed before the intended boundary'
        );
        $wrongTtbIncident = $expect(
            mysqli_sql_exception::class,
            static fn (): null => ($directTtbInsert(
                $loosePermissionIncidentId + 1000000,
                $loosePermissionMessageId
            ) ?? null),
            'LOOSE TTB trigger accepted another incident'
        );
        $assert(
            str_contains(
                $wrongTtbIncident->getMessage(),
                'Operational insert targets inactive incident'
            ),
            'LOOSE TTB incident probe failed before the intended boundary'
        );

        $setPermissionActorBlocked = static function (
            bool $blocked
        ) use ($connection, $codes): void {
            $statement = $connection->prepare(
                'UPDATE `nv_benutzer` SET `estab_gesperrt` = ?'
                    . ' WHERE BINARY `kuerzel` = BINARY ?'
            );
            try {
                $blockedValue = $blocked ? 1 : 0;
                $statement->bind_param(
                    'is',
                    $blockedValue,
                    $codes['s3']
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        };
        $setPermissionActorBlocked(true);
        try {
            $expect(
                EstabDvPermissionException::class,
                static fn (): int => estab_logbook_insert_entry(
                    $databaseConfig,
                    'nv_etb',
                    'etb',
                    $permissionEtbEntry('blocked-account'),
                    $s3Identity
                ),
                'LOOSE admitted a blocked account into ETB'
            );
            $expect(
                EstabDvPermissionException::class,
                static fn (): int => estab_logbook_insert_entry(
                    $databaseConfig,
                    'nv_tbb',
                    'tbb',
                    $permissionTtbEntry('blocked-account'),
                    $s3Identity
                ),
                'LOOSE admitted a blocked account into TTB'
            );
            $expect(
                EstabDvPermissionException::class,
                static fn (): int => estab_dv_assign_messenger(
                    $connection,
                    $loosePermissionIncidentId,
                    $loosePermissionMessageId,
                    $codes['messenger'],
                    'Gegenstelle Integration',
                    $s3Identity
                ),
                'LOOSE admitted a blocked messenger supervisor'
            );
        } finally {
            $setPermissionActorBlocked(false);
        }
        $assert(
            (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
                'i',
                $loosePermissionIncidentId
            ) === $permissionModeEtbBefore + 1
                && (int) $scalar(
                    $connection,
                    'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
                    'i',
                    $loosePermissionIncidentId
                ) === $permissionModeTtbBefore + 1
                && (int) $scalar(
                    $connection,
                    'SELECT COUNT(*) FROM `nv_melderauftraege`'
                        . ' WHERE `einsatz_id` = ?',
                    'i',
                    $loosePermissionIncidentId
                ) === $permissionModeJobsBefore + 1,
            'LOOSE negative probes left partial ETB, TTB or messenger rows'
        );
    } finally {
        $modeBeforeStrictRestore = estab_incident_status($connection);
        if (
            (int) ($modeBeforeStrictRestore['active_einsatz_id'] ?? 0)
                === $loosePermissionIncidentId
        ) {
            $strictPermissionIncident = estab_incident_activate(
                $connection,
                $incidentId,
                (int) $modeBeforeStrictRestore['revision'],
                'dv-permission-mode-matrix'
            );
        } else {
            $strictPermissionIncident = $modeBeforeStrictRestore;
        }
        estab_permission_context_set_from_incident($strictPermissionIncident);
    }

    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_logbook_insert_entry(
            $databaseConfig,
            'nv_etb',
            'etb',
            $permissionEtbEntry('strict-restored'),
            $s3Identity
        ),
        'restored STRICT did not close S3 ETB access'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_logbook_insert_entry(
            $databaseConfig,
            'nv_tbb',
            'tbb',
            $permissionTtbEntry('strict-restored'),
            $s3Identity
        ),
        'restored STRICT did not close S3 TTB access'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_dv_assign_messenger(
            $connection,
            $incidentId,
            $messageId,
            $codes['messenger'],
            'Gegenstelle Integration',
            $s3Identity
        ),
        'restored STRICT did not close S3 messenger supervision'
    );
    $assert(
        ($strictPermissionIncident['estab_permission_mode'] ?? null)
            === 'STRICT'
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_einsatz_ereignisse`'
                    . ' WHERE `einsatz_id` = ?'
                    . " AND `aktion` = 'berechtigung_geaendert'",
                'i',
                $incidentId
            ) === $strictPermissionModeAuditBefore
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_einsatz_ereignisse`'
                    . ' WHERE `einsatz_id` = ?'
                    . " AND `aktion` = 'berechtigung_geaendert'",
                'i',
                $loosePermissionIncidentId
            ) === $loosePermissionModeAuditBefore
            && (int) $scalar(
                $connection,
                'SELECT `estab_gesperrt` FROM `nv_benutzer`'
                    . ' WHERE BINARY `kuerzel` = BINARY ?',
                's',
                $codes['s3']
            ) === 0
            && (string) $scalar(
                $connection,
                'SELECT `status` FROM `nv_melderauftraege`'
                    . ' WHERE `melderauftrag_id` = ?',
                'i',
                $looseModeJobId
            ) === 'ABGEBROCHEN',
        'permission-mode matrix did not reactivate immutable STRICT or retain '
            . 'the terminal LOOSE fixture state'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_benutzer_zusatzfunktionen`'
                . ' WHERE BINARY `benutzer_kuerzel` = BINARY ?',
            's',
            $s3Identity['kuerzel']
        ) === 4,
        'STRICT boundary was not exercised while loose grants still existed'
    );
    $deleteLooseGrants = $connection->prepare(
        'DELETE FROM `nv_benutzer_zusatzfunktionen`'
            . ' WHERE BINARY `benutzer_kuerzel` = BINARY ?'
    );
    if (!$deleteLooseGrants) {
        throw new RuntimeException('Could not prepare loose-grant cleanup');
    }
    try {
        $deleteGrantCode = $s3Identity['kuerzel'];
        $deleteLooseGrants->bind_param('s', $deleteGrantCode);
        $deleteLooseGrants->execute();
    } finally {
        $deleteLooseGrants->close();
    }

    if (!$connection->begin_transaction()) {
        throw new RuntimeException(
            'Could not start STRICT capability-catalogue probe'
        );
    }
    try {
        $removeStrictCapability = $connection->prepare(
            'DELETE FROM `nv_funktionsfaehigkeiten`'
                . ' WHERE BINARY `funktion` = BINARY ?'
                . ' AND BINARY `rolle` = BINARY ?'
                . ' AND BINARY `faehigkeit` = BINARY ?'
        );
        if (!$removeStrictCapability) {
            throw new RuntimeException(
                'Could not prepare STRICT capability probe'
            );
        }
        try {
            $strictFunction = (string) $ldfIdentity['funktion'];
            $strictRole = (string) $ldfIdentity['rolle'];
            $strictCapability = 'FERNMELDEBETRIEB';
            $removeStrictCapability->bind_param(
                'sss',
                $strictFunction,
                $strictRole,
                $strictCapability
            );
            $removeStrictCapability->execute();
            $assert(
                $removeStrictCapability->affected_rows === 1,
                'STRICT capability-catalogue probe removed no capability'
            );
        } finally {
            $removeStrictCapability->close();
        }
        $expect(
            EstabDvPermissionException::class,
            static fn (): array => estab_dv_require_write_capability(
                $connection,
                $incidentId,
                $ldfIdentity,
                'FERNMELDEBETRIEB'
            ),
            'STRICT write authority ignored the selected function capability'
        );
        $expect(
            EstabDvPermissionException::class,
            static fn (): array => estab_dv_require_account_capability(
                $connection,
                $incidentId,
                $ldfIdentity,
                'FERNMELDEBETRIEB'
            ),
            'STRICT read authority ignored the selected function capability'
        );
        $expect(
            EstabReadPermissionException::class,
            static fn (): array => estab_read_require_identity_scope(
                $connection,
                $incidentId,
                $ldfIdentity
            ),
            'STRICT generic read scope ignored the selected function capability'
        );
    } finally {
        $connection->rollback();
    }

    $setStrictMessengerSignedOut = $connection->prepare(
        'UPDATE `nv_benutzer` SET `aktiv` = 1, `sid` = ?,'
            . ' `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
            . ' WHERE BINARY `kuerzel` = BINARY ?'
    );
    if (!$setStrictMessengerSignedOut) {
        throw new RuntimeException(
            'Could not prepare STRICT messenger presence probe'
        );
    }
    try {
        $malformedSessionId = 'invalid session!';
        $setStrictMessengerSignedOut->bind_param(
            'ss',
            $malformedSessionId,
            $codes['messenger']
        );
        $setStrictMessengerSignedOut->execute();
    } finally {
        $setStrictMessengerSignedOut->close();
    }
    $setStrictMessengerInactive = $connection->prepare(
        'UPDATE `nv_benutzer` SET `aktiv` = 1,'
            . ' `estab_letzte_aktivitaet` ='
            . ' UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE'
            . ' WHERE BINARY `kuerzel` = BINARY ?'
    );
    if (!$setStrictMessengerInactive) {
        throw new RuntimeException(
            'Could not prepare STRICT inactive messenger probe'
        );
    }
    try {
        $setStrictMessengerInactive->bind_param(
            's',
            $codes['aw_extension']
        );
        $setStrictMessengerInactive->execute();
    } finally {
        $setStrictMessengerInactive->close();
    }
    $messengerCandidates = estab_dv_messenger_candidates(
        $connection,
        $incidentId
    );
    $candidateCodes = array_column($messengerCandidates, 'kuerzel');
    $candidateAssignments = array_column(
        $messengerCandidates,
        'dienstbesetzung_id',
        'kuerzel'
    );
    $strictCandidatesByCode = array_column(
        $messengerCandidates,
        null,
        'kuerzel'
    );
    sort($candidateCodes);
    $expectedCandidateCodes = [
        $codes['aw'],
        $codes['messenger'],
        $codes['aw_extension'],
    ];
    sort($expectedCandidateCodes);
    $assert(
        $candidateCodes === $expectedCandidateCodes
            && !in_array($codes['s2'], $candidateCodes, true)
            && (int) ($candidateAssignments[$codes['aw']] ?? 0)
                === $assignments['aw']
            && (int) ($candidateAssignments[$codes['messenger']] ?? 0)
                === $assignments['messenger']
            && (int) ($candidateAssignments[$codes['aw_extension']] ?? 0)
                === $awExtensionId
            && ($strictCandidatesByCode[$codes['messenger']]
                ['presence_state'] ?? null) === 'signed_out'
            && ($strictCandidatesByCode[$codes['messenger']]
                ['requires_separate_notification'] ?? null) === true
            && ($strictCandidatesByCode[$codes['aw_extension']]
                ['presence_state'] ?? null) === 'inactive'
            && !array_key_exists(
                'sid',
                $strictCandidatesByCode[$codes['messenger']] ?? []
            )
            && !array_key_exists(
                'aktiv',
                $strictCandidatesByCode[$codes['messenger']] ?? []
            ),
        'STRICT messenger UI did not retain exact accepted A/W assignments '
            . 'with non-sensitive inactive and signed-out presence'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_dv_assign_messenger(
            $connection,
            $incidentId,
            $messageId,
            $codes['messenger'],
            'Gegenstelle Integration',
            [
                'benutzer' => 'Leiter FmZt',
                'kuerzel' => $codes['ldf'],
                'funktion' => 'S2',
                'rolle' => 'Stab',
            ]
        ),
        'LdF privilege ignored the account’s fixed function'
    );

    $strictAssignmentDetails = null;
    $cancelledJobId = estab_dv_assign_messenger(
        $connection,
        $incidentId,
        $messageId,
        $codes['messenger'],
        'Gegenstelle Integration',
        $ldfIdentity,
        'nv_protokoll',
        $strictAssignmentDetails
    );
    $assert(
        $cancelledJobId > 0
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_melderauftraege`'
                    . ' WHERE `einsatz_id` = ? AND `melderauftrag_id` = ?',
                'ii',
                $incidentId,
                $cancelledJobId
            ) === 1,
        'messenger dispatch lost its insert id while clearing SQL authority'
    );
    $assert(
        is_array($strictAssignmentDetails)
            && ($strictAssignmentDetails['job_id'] ?? null)
                === $cancelledJobId
            && ($strictAssignmentDetails['presence_state'] ?? null)
                === 'signed_out'
            && ($strictAssignmentDetails[
                'requires_separate_notification'
            ] ?? null) === true,
        'STRICT messenger dispatch did not return its non-sensitive presence '
            . 'and notification duty'
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT CONCAT('
                . "JSON_UNQUOTE(JSON_EXTRACT(`details`, '$.permission_mode')) ,"
                . ' ?, '
                . "JSON_UNQUOTE(JSON_EXTRACT(`details`, '$.messenger_function')) ,"
                . ' ?, '
                . "JSON_UNQUOTE(JSON_EXTRACT(`details`, '$.messenger_role')) ,"
                . ' ?, CAST(JSON_UNQUOTE(JSON_EXTRACT('
                . "`details`, '$.messenger_duty_assignment_id')) AS UNSIGNED))"
                . ' FROM `nv_betriebsereignisse`'
                . ' WHERE `einsatz_id` = ?'
                . " AND `objekttyp` = 'MELDERAUFTRAG'"
                . ' AND `objekt_id` = ?'
                . " AND `aktion` = 'messenger_assigned'",
            'sssii',
            '|',
            '|',
            '|',
            $incidentId,
            $cancelledJobId
        ) === 'STRICT|A/W|Fernmelder|' . $assignments['messenger'],
        'STRICT messenger assignment lost its exact target duty provenance'
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT CONCAT('
                . "JSON_UNQUOTE(JSON_EXTRACT(`details`,"
                . " '$.messenger_presence_state')), ?,"
                . " JSON_UNQUOTE(JSON_EXTRACT(`details`,"
                . " '$.separate_notification_required')))"
                . ' FROM `nv_betriebsereignisse`'
                . ' WHERE `einsatz_id` = ?'
                . " AND `objekttyp` = 'MELDERAUFTRAG'"
                . ' AND `objekt_id` = ?'
                . " AND `aktion` = 'messenger_assigned'",
            'sii',
            '|',
            $incidentId,
            $cancelledJobId
        ) === 'signed_out|true',
        'STRICT messenger audit lost presence and separate-notification duty'
    );
    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $cancelledJobId,
            'cancel',
            $ldfIdentity,
            ['abbruchgrund' => 'Vor Übernahme neu zu disponieren']
        ) === 'ABGEBROCHEN',
        'pre-acceptance cancellation was not retained'
    );
    $restoreStrictMessengerSession = $connection->prepare(
        'UPDATE `nv_benutzer` SET `aktiv` = 1, `sid` = ?,'
            . ' `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
            . ' WHERE BINARY `kuerzel` = BINARY ?'
    );
    if (!$restoreStrictMessengerSession) {
        throw new RuntimeException(
            'Could not prepare STRICT messenger presence restore'
        );
    }
    try {
        $restoredMessengerSession =
            'dv-session-' . $suffix . '-' . $codes['messenger'];
        $restoreStrictMessengerSession->bind_param(
            'ss',
            $restoredMessengerSession,
            $codes['messenger']
        );
        $restoreStrictMessengerSession->execute();
        $restoredExtensionSession =
            'dv-session-' . $suffix . '-' . $codes['aw_extension'];
        $restoreStrictMessengerSession->bind_param(
            'ss',
            $restoredExtensionSession,
            $codes['aw_extension']
        );
        $restoreStrictMessengerSession->execute();
    } finally {
        $restoreStrictMessengerSession->close();
    }
    $redispatchHistory = estab_dv_require_no_open_messenger_for_redispatch(
        $connection,
        $incidentId,
        $messageId
    );
    $assert(
        count($redispatchHistory) === 1
            && (int) $redispatchHistory[0]['melderauftrag_id']
                === $cancelledJobId
            && $redispatchHistory[0]['status'] === 'ABGEBROCHEN',
        'an aborted pre-acceptance run did not release the message for '
            . 'traceable incident-scoped redispatch'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_require_messenger_reported_for_message(
            $connection,
            $incidentId,
            $messageId
        ),
        'cancelled messenger chain passed the message completion gate'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): int => estab_dv_assign_messenger(
            $connection,
            $incidentId,
            $messageId,
            $codes['s2'],
            'Gegenstelle Integration',
            $ldfIdentity
        ),
        'an active S2 account was accepted as a messenger'
    );
    $jobId = estab_dv_assign_messenger(
        $connection,
        $incidentId,
        $messageId,
        $codes['messenger'],
        'Gegenstelle Integration',
        $ldfIdentity
    );
    $assert(
        $jobId !== $cancelledJobId,
        'a cancelled pre-acceptance run did not permit traceable reassignment'
    );

    $attachmentSession = 'dv_attach_' . $suffix;
    $attachmentName = estab_attachment_reserve(
        $connection,
        'nv_anhang',
        'DV',
        $attachmentSession,
        $messengerIdentity
    );

    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'accept',
            $messengerIdentity
        ) === 'UEBERNOMMEN',
        'messenger did not personally accept the job'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array =>
            estab_dv_require_no_open_messenger_for_redispatch(
                $connection,
                $incidentId,
                $messageId
            ),
        'an accepted messenger run did not block redispatch'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): string => estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'cancel',
            $ldfIdentity,
            ['abbruchgrund' => 'Unzulässiger Abbruch nach Übernahme']
        ),
        'an accepted messenger job was cancelled without return evidence'
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $jobId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_melderauftraege`'
                . " SET `status` = 'ABGEBROCHEN',"
                . ' `abgebrochen_am` = NOW(6), `abbruchgrund` = ?'
                . ' WHERE `melderauftrag_id` = ?'
            );
            $reason = 'Direkter Abbruch ohne Rückkehr';
            try {
                $statement->bind_param('si', $reason, $jobId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'database accepted post-acceptance cancellation without return'
    );
    $secondShift = estab_dv_create_shift(
        $connection,
        $incidentId,
        'Dienstschicht 2',
        $shiftId,
        $actor
    );
    $secondShiftId = (int) $secondShift['dienstschicht_id'];
    $setSuccessorOnline = $connection->prepare(
        'UPDATE `nv_benutzer` SET `aktiv` = ?,'
        . ' `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
        . ' WHERE BINARY `kuerzel` = BINARY ?'
    );
    if (!$setSuccessorOnline) {
        throw new RuntimeException('Could not prepare offline planning probe');
    }
    $offline = 0;
    $setSuccessorOnline->bind_param(
        'is',
        $offline,
        $codes['s2_successor']
    );
    $setSuccessorOnline->execute();
    foreach ($hatDefinitions as $accountKey => $function) {
        $successorAccountKey = $accountKey === 's2'
            ? 's2_successor'
            : $accountKey;
        $assignment = estab_dv_assign_hat(
            $connection,
            $incidentId,
            $secondShiftId,
            $codes[$successorAccountKey],
            $function,
            $actor
        );
        $secondAssignments[$accountKey] =
            (int) $assignment['dienstbesetzung_id'];
        if ($accountKey === 's2') {
            $offlineAcceptance = estab_dv_accept_hat(
                $connection,
                $incidentId,
                $secondAssignments[$accountKey],
                $codes[$successorAccountKey]
            );
            $assert(
                (int) $offlineAcceptance['dienstbesetzung_id']
                    === $secondAssignments[$accountKey],
                'offline but unblocked account could not retain its '
                    . 'personally recorded planned duty acceptance'
            );
            continue;
        }
        estab_dv_accept_hat(
            $connection,
            $incidentId,
            $secondAssignments[$accountKey],
            $codes[$successorAccountKey]
        );
    }
    foreach (
        [
            's3' => [$codes['s2_successor'], 'S3'],
            'etb' => [$codes['si'], 'ETB'],
        ] as $assignmentKey => [$accountCode, $function]
    ) {
        $assignment = estab_dv_assign_hat(
            $connection,
            $incidentId,
            $secondShiftId,
            $accountCode,
            $function,
            $actor
        );
        $secondAssignments[$assignmentKey] =
            (int) $assignment['dienstbesetzung_id'];
        if ($assignmentKey === 'etb') {
            continue;
        }
        estab_dv_accept_hat(
            $connection,
            $incidentId,
            $secondAssignments[$assignmentKey],
            $accountCode
        );
    }
    $assert(
        estab_dv_shift_required_hats($connection, $secondShiftId) === [],
        'offline but accepted unblocked account did not fulfil planned staffing'
    );
    $unacceptedSuccessor = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $secondShiftId,
        $codes['ldf_duplicate'],
        'A/W',
        $actor
    );
    $unacceptedSuccessorAssignmentId =
        (int) $unacceptedSuccessor['dienstbesetzung_id'];
    $assert(
        (string) $scalar(
            $connection,
            'SELECT `status` FROM `nv_dienstbesetzungen`'
                . ' WHERE `dienstbesetzung_id` = ?',
            'i',
            $unacceptedSuccessorAssignmentId
        ) === 'ZUGEWIESEN',
        'unaccepted successor roster fixture is not assigned-only'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_dv_initiate_handover_shift(
            $connection,
            $incidentId,
            $shiftId,
            $secondShiftId,
            'Unzulässige administrative Übergabe ohne persönliche Besetzung.',
            $assignments['s2'],
            [
                'benutzer' => 'Integration Administration',
                'kuerzel' => 'admin',
                'funktion' => 'S2',
                'rolle' => 'Stab',
            ],
            $actor
        ),
        'administrator initiated a handover without a personal accepted hat'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_dv_initiate_handover_shift(
            $connection,
            $incidentId,
            $shiftId,
            $secondShiftId,
            'Unzulässige Übergabe mit fremder Besetzungs-ID.',
            $assignments['s2'],
            $awIdentity,
            $actor
        ),
        'personally logged-in A/W claimed another accepted assignment for handover'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): null => (
            estab_dv_close_shift(
                $connection,
                $incidentId,
                $shiftId,
                $actor
            ) ?? null
        ),
        'active shift closed although successor operation was prepared'
    );
    $blockUser = $connection->prepare(
        'UPDATE `nv_benutzer` SET `estab_gesperrt` = ?'
        . ' WHERE BINARY `kuerzel` = BINARY ?'
    );
    if (!$blockUser) {
        throw new RuntimeException('Could not prepare blocked-user probe');
    }
    try {
        $blocked = 1;
        $blockUser->bind_param('is', $blocked, $codes['s6']);
        $blockUser->execute();
        $assert(
            estab_dv_shift_required_hats(
                $connection,
                $secondShiftId
            ) === ['S6'],
            'blocked S6 account still fulfilled a required successor hat'
        );
        $expect(
            EstabDvConflictException::class,
            static fn (): int => estab_dv_initiate_handover_shift(
                $connection,
                $incidentId,
                $shiftId,
                $secondShiftId,
                'Unzulässig mit gesperrter Pflichtbesetzung.',
                $assignments['s2'],
                $s2Identity,
                $actor
            ),
            'blocked account fulfilled handover mandatory staffing'
        );
        $blocked = 0;
        $blockUser->execute();
    } finally {
        $blockUser->close();
    }
    $expect(
        EstabDvConflictException::class,
        static fn (): null => (
            estab_dv_initiate_handover_shift(
                $connection,
                $incidentId,
                $shiftId,
                $secondShiftId,
                'Unzulässige Übergabe bei unterwegs befindlichem Melder.',
                $assignments['s2'],
                $s2Identity,
                $actor
            ) ?? null
        ),
        'shift handover superseded a messenger who was still away'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $messengerIdentity
            ) ?? null
        ),
        'messenger wrote another task while in status UEBERNOMMEN'
    );
    $uploadCallbackCalls = 0;
    $blockedJpegUpload = static function () use (
        &$uploadCallbackCalls,
        $attachmentName,
        $suffix
    ): array {
        $uploadCallbackCalls++;
        return [
            'filename' => $attachmentName . '.jpg',
            'org_filename' => 'lagefoto-' . $suffix . '.jpg',
            'comment' => 'DV Melderguard JPEG',
            'time' => date('Y-m-d H:i:s'),
            'md5hash' => md5('dv-jpeg-' . $suffix),
            'sha256' => hash('sha256', 'dv-jpeg-' . $suffix),
            'size' => strlen('dv-jpeg-' . $suffix),
        ];
    };
    $attachmentAudit = static fn (array $stored): string =>
        'dv-melderguard;' . $stored['filename'] . '.' . $stored['fileext'];
    $expect(
        EstabDvPermissionException::class,
        static fn (): ?array => estab_attachment_store_upload(
            $connection,
            'nv_anhang',
            'nv_protokoll',
            $attachmentName,
            $attachmentSession,
            $codes['messenger'],
            $messengerIdentity,
            'Anhangdaten speichern',
            $blockedJpegUpload,
            $attachmentAudit
        ),
        'JPEG attachment was stored while messenger was away'
    );
    $assert(
        $uploadCallbackCalls === 0,
        'blocked away upload touched JPEG bytes before authorization'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_logbook_insert_entry(
            $databaseConfig,
            'nv_tbb',
            'tbb',
            [
                'event' => 'Unzulässiger Parallelauftrag',
                'comment' => 'Melder ist unterwegs.',
            ],
            $messengerIdentity
        ),
        'messenger wrote TBB evidence while away'
    );

    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'deliver',
            $messengerIdentity,
            ['tatsaechlicher_empfaenger' => 'Frau Zielperson']
        ) === 'UEBERGEBEN',
        'actual recipient was not recorded at delivery'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $messengerIdentity
            ) ?? null
        ),
        'messenger wrote another task after delivery but before return path'
    );
    $expect(
        EstabDvInputException::class,
        static fn (): string => estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'return_path',
            $messengerIdentity,
            ['ruecknachricht' => 'Mehrdeutiger Rückweg']
        ),
        'return path accepted text without an explicit message decision'
    );
    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'return_path',
            $messengerIdentity,
            [
                'ruecknachricht_vorhanden' => 'ja',
                'ruecknachricht' => 'Empfang bestätigt',
            ]
        ) === 'RUECKWEG',
        'messenger return path was not recorded'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $messengerIdentity
            ) ?? null
        ),
        'messenger wrote another task while returning'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): ?array => estab_attachment_store_upload(
            $connection,
            'nv_anhang',
            'nv_protokoll',
            $attachmentName,
            $attachmentSession,
            $codes['messenger'],
            $messengerIdentity,
            'Anhangdaten speichern',
            $blockedJpegUpload,
            $attachmentAudit
        ),
        'JPEG attachment was stored while messenger was on the return path'
    );
    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'returned',
            $messengerIdentity
        ) === 'ZURUECK',
        'messenger return was not recorded'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_require_messenger_reported_for_message(
            $connection,
            $incidentId,
            $messageId
        ),
        'returned but unreported messenger chain passed completion'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): string => estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'report',
            $awIdentity,
            ['abschlussvermerk' => 'Unzulässige A/W-Abschlussmeldung']
        ),
        'a non-LdF A/W account recorded the final FmZt report'
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $jobId, $codes): void {
            $statement = $connection->prepare(
                'UPDATE `nv_melderauftraege`'
                . " SET `status` = 'GEMELDET', `gemeldet_am` = NOW(6),"
                . ' `gemeldet_an` = ?, `abschlussvermerk` = ?,'
                . ' `ziel` = ?, `tatsaechlicher_empfaenger` = ?'
                . ' WHERE `melderauftrag_id` = ?'
            );
            $report = 'Formal vollständiger, aber manipulierter Abschluss';
            $destination = 'Nachträglich geändertes Ziel';
            $recipient = 'Nachträglich geänderter Empfänger';
            try {
                $statement->bind_param(
                    'ssssi',
                    $codes['ldf'],
                    $report,
                    $destination,
                    $recipient,
                    $jobId
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'legitimate messenger transition altered destination or prior evidence'
    );
    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'report',
            $ldfIdentity,
            ['abschlussvermerk' => 'Rückkehr und Empfang an FmZt gemeldet']
        ) === 'GEMELDET',
        'selected active LdF could not record the final FmZt report'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array =>
            estab_dv_require_no_open_messenger_for_redispatch(
                $connection,
                $incidentId,
                $messageId
            ),
        'a completely reported messenger run did not block redispatch '
            . 'permanently'
    );
    $storedJpeg = estab_attachment_store_upload(
        $connection,
        'nv_anhang',
        'nv_protokoll',
        $attachmentName,
        $attachmentSession,
        $codes['messenger'],
        $messengerIdentity,
        'Anhangdaten speichern',
        $blockedJpegUpload,
        $attachmentAudit
    );
    $assert(
        is_array($storedJpeg)
            && ($storedJpeg['fileext'] ?? null) === 'jpg'
            && $uploadCallbackCalls === 1,
        'reported messenger did not regain JPEG attachment write access'
    );
    estab_dv_require_operational_account(
        $connection,
        $incidentId,
        $messengerIdentity
    );
    $assert(
        !estab_logbook_is_designated_writer(
            $connection,
            $incidentId,
            $messengerIdentity,
            'tbb'
        )
            && estab_logbook_is_designated_writer(
                $connection,
                $incidentId,
                $awIdentity,
                'tbb'
            ),
        'STRICT no longer retains one exact designated A/W assignment for TTB'
    );
    $tbbEntryId = estab_logbook_insert_entry(
        $databaseConfig,
        'nv_tbb',
        'tbb',
        [
            'entry_type' => 'betrieb_personal',
            'personnel_duty' => 'Melder zurück in der Führungsstelle',
            'comment' => 'TBB-Nachtrag durch ein festes A/W-Konto.',
        ],
        $awIdentity
    );
    $assert(
        $tbbEntryId > 0,
        'fixed A/W account could not record the returned messenger in the TBB'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): int => estab_dv_assign_messenger(
            $connection,
            $incidentId,
            $messageId,
            $codes['aw'],
            'Gegenstelle Integration',
            $ldfIdentity
        ),
        'a reported message was assigned a second time while still status 2'
    );
    $completedJob = estab_dv_require_messenger_reported_for_message(
        $connection,
        $incidentId,
        $messageId
    );
    $assert(
        (int) $completedJob['melderauftrag_id'] === $jobId,
        'completed messenger evidence did not bind the exact message'
    );
    $messengerSnapshots = estab_dv_verify_messenger_snapshots(
        $connection,
        $incidentId
    );
    $assert(
        $messengerSnapshots['valid'] === true
            && (int) $messengerSnapshots['jobs'] === 2,
        'incident-scoped terminal messenger rows do not match their canonical '
            . 'event snapshots'
    );

    // The legacy connection shim disables mysqli exception reporting when the
    // logbook opens its own connection. Re-enable strict reporting so the
    // following direct tamper probes can assert the database-trigger errors.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use (
            $connection,
            $incidentId,
            $messageId,
            $codes
        ): void {
            $statement = $connection->prepare(
                'INSERT INTO `nv_melderauftraege`'
                . ' (`einsatz_id`, `nachricht_id`, `melder_kuerzel`,'
                . ' `ziel`, `beauftragt_von`) VALUES (?, ?, ?, ?, ?)'
            );
            $forgedDestination = 'Zweiter Lauf nach Abschluss';
            try {
                $statement->bind_param(
                    'iisss',
                    $incidentId,
                    $messageId,
                    $codes['aw'],
                    $forgedDestination,
                    $codes['ldf']
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'database trigger allowed a second assignment after GEMELDET'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $jobId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_melderauftraege`'
                . ' SET `abschlussvermerk` = ?'
                . ' WHERE `melderauftrag_id` = ?'
            );
            $forgedReport = 'Nachträglich manipuliert';
            try {
                $statement->bind_param('si', $forgedReport, $jobId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'terminal messenger snapshot was mutable after final report'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $routeId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_fernmeldeplan_eintraege`'
                . ' SET `rufname` = ? WHERE `fernmeldeplan_eintrag_id` = ?'
            );
            $changedCallSign = 'Manipuliert';
            try {
                $statement->bind_param('si', $changedCallSign, $routeId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'an active telecommunications route was mutable'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $messageId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_nachrichten`'
                . ' SET `estab_fernmeldeplan_eintrag_id` = NULL'
                . ' WHERE `00_lfd` = ?'
            );
            try {
                $statement->bind_param('i', $messageId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'the disposed message route was mutable'
    );

    $expectedRevisionVersion = (int) $scalar(
        $connection,
        'SELECT COALESCE(MAX(`version`), 0) + 1'
            . ' FROM `nv_fernmeldeplaene` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $barrierBase = tempnam(
        sys_get_temp_dir(),
        'estab-telecom-revision-contenders-'
    );
    if (!is_string($barrierBase)) {
        throw new RuntimeException(
            'Telecommunications contender barrier could not be allocated'
        );
    }
    unlink($barrierBase);
    $readyFiles = [$barrierBase . '.one', $barrierBase . '.two'];
    $revisionWorkers = [];
    $incidentLockHeld = false;
    try {
        $connection->begin_transaction();
        $incidentLockHeld = true;
        $incidentLock = $connection->query(
            'SELECT `revision` FROM `nv_einsatz_status`'
                . ' WHERE `singleton_id` = 1 FOR UPDATE'
        );
        if (!$incidentLock) {
            throw new RuntimeException(
                'Telecommunications contender lock could not be acquired'
            );
        }
        $incidentLock->free();
        foreach ($readyFiles as $readyFile) {
            $revisionWorkers[] = $startTelecomRevisionWorker(
                $incidentId,
                $planId,
                $s6Identity,
                $readyFile
            );
        }
        $readyDeadline = microtime(true) + 10.0;
        foreach ($readyFiles as $readyFile) {
            while (!is_file($readyFile)) {
                if (microtime(true) >= $readyDeadline) {
                    throw new RuntimeException(
                        'Telecommunications contenders did not become ready'
                    );
                }
                usleep(10_000);
            }
        }
        // Both workers have connected and entered the domain operation. Keep
        // the singleton lock briefly so measured wait evidence cannot be a
        // merely sequential pair of calls.
        usleep(250_000);
        $connection->commit();
        $incidentLockHeld = false;
    } catch (Throwable $exception) {
        if ($incidentLockHeld) {
            $connection->rollback();
        }
        foreach ($revisionWorkers as $worker) {
            if (is_resource($worker['process'] ?? null)) {
                proc_terminate($worker['process']);
            }
        }
        throw $exception;
    } finally {
        foreach ($readyFiles as $readyFile) {
            if (is_file($readyFile)) {
                unlink($readyFile);
            }
        }
    }
    $revisionRace = array_map(
        $finishTelecomRevisionWorker,
        $revisionWorkers
    );
    $successfulRevisions = array_values(array_filter(
        $revisionRace,
        static fn (array $candidate): bool =>
            ($candidate['status'] ?? null) === 'success'
    ));
    $conflictingRevisions = array_values(array_filter(
        $revisionRace,
        static fn (array $candidate): bool =>
            ($candidate['status'] ?? null) === 'conflict'
    ));
    $assert(
        count($successfulRevisions) === 1
            && count($conflictingRevisions) === 1
            && min(array_map(
                static fn (array $candidate): float =>
                    (float) ($candidate['elapsed_ms'] ?? 0.0),
                $revisionRace
            )) >= 150.0
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_fernmeldeplaene`'
                    . " WHERE `einsatz_id` = ? AND `status` = 'ENTWURF'",
                'i',
                $incidentId
            ) === 1,
        'two real revision contenders did not wait and serialize to one draft'
    );
    $revision = $successfulRevisions[0]['result'] ?? null;
    if (!is_array($revision)) {
        throw new RuntimeException(
            'Winning telecommunications contender returned no plan'
        );
    }
    $revisionPlanId = (int) $revision['fernmeldeplan_id'];
    $assert(
        $revisionPlanId > 0
            && $revisionPlanId !== $planId
            && (int) $revision['version'] === $expectedRevisionVersion
            && (int) $revision['source_plan_id'] === $planId
            && (int) $revision['copied_entries'] === 2,
        'active telecommunications plan was not cloned with the next '
            . 'incident-local version and all entries'
    );
    $plansAfterClone = estab_dv_telecom_plans($connection, $incidentId);
    $sourceAfterClone = null;
    $draftAfterClone = null;
    foreach ($plansAfterClone as $candidate) {
        if ((int) $candidate['fernmeldeplan_id'] === $planId) {
            $sourceAfterClone = $candidate;
        }
        if ((int) $candidate['fernmeldeplan_id'] === $revisionPlanId) {
            $draftAfterClone = $candidate;
        }
    }
    $assert(
        is_array($sourceAfterClone)
            && $sourceAfterClone['status'] === 'AKTIV'
            && is_array($draftAfterClone)
            && $draftAfterClone['status'] === 'ENTWURF',
        'cloned plan changed its active source or draft state'
    );
    $sourceEntryIds = array_map(
        static fn (array $entry): int =>
            (int) $entry['fernmeldeplan_eintrag_id'],
        $sourceAfterClone['eintraege']
    );
    $draftEntryIds = array_map(
        static fn (array $entry): int =>
            (int) $entry['fernmeldeplan_eintrag_id'],
        $draftAfterClone['eintraege']
    );
    $copiedEntryFields = array_fill_keys(
        [
            'sortierung',
            'betriebsstelle',
            'rufname',
            'medium',
            'kanal',
            'bandlage',
            'verkehrsform',
            'besondere_vermerke',
            'bemerkungen',
        ],
        true
    );
    $entryCopyState = static fn (array $entry): array =>
        array_intersect_key($entry, $copiedEntryFields);
    $sourceEntryStates = array_map(
        $entryCopyState,
        $sourceAfterClone['eintraege']
    );
    $draftEntryStates = array_map(
        $entryCopyState,
        $draftAfterClone['eintraege']
    );
    $assert(
        estab_dv_telecom_plan_header_audit_state($draftAfterClone)
            === estab_dv_telecom_plan_header_audit_state($sourceAfterClone)
            && $sourceEntryIds === [$routeId, $secondaryRouteId]
            && count($draftEntryIds) === 2
            && min($draftEntryIds) > 0
            && count(array_unique($draftEntryIds)) === 2
            && array_intersect($sourceEntryIds, $draftEntryIds) === []
            && $draftEntryStates === $sourceEntryStates
            && $sourceEntryStates[0]['besondere_vermerke']
                === 'Identität am Ziel feststellen'
            && $sourceEntryStates[0]['bemerkungen']
                === 'Rückweg über FmZt'
            && $sourceEntryStates[1]['besondere_vermerke']
                === 'Priorisierte Führungsverbindung'
            && $sourceEntryStates[1]['bemerkungen']
                === 'Rückfallebene über Fernsprecher',
        'clone changed complete header or route fields, optional notes, or IDs'
    );
    /*
     * Die Kennung ueberlebt den Versionswechsel -- das ist ihr ganzer Zweck.
     * Die Zeilenkennungen sind neu (oben geprueft), die Wegkennungen sind
     * dieselben. Beides zusammen ist die Aussage: eine neue Fassung
     * desselben Wegs, nicht ein neuer Weg.
     */
    $routeIdentities = static fn (array $plan): array => array_map(
        static fn (array $entry): array => [
            (int) ($entry['weg_id'] ?? 0),
            (int) ($entry['weg_nummer'] ?? 0),
        ],
        $plan['eintraege']
    );
    $sourceIdentities = $routeIdentities($sourceAfterClone);
    $draftIdentities = $routeIdentities($draftAfterClone);
    $assert(
        $sourceIdentities === $draftIdentities
            && count($sourceIdentities) === 2
            && min(array_merge(...$sourceIdentities)) > 0
            && $sourceIdentities[0][0] !== $sourceIdentities[1][0]
            && $sourceIdentities[0][1] !== $sourceIdentities[1][1],
        'cloned draft did not inherit the durable route identities'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(DISTINCT `weg_id`)'
                . ' FROM `nv_fernmeldeweg_zuordnung` AS zu'
                . ' JOIN `nv_fernmeldeplaene` AS p'
                . ' ON p.`fernmeldeplan_id` = zu.`fernmeldeplan_id`'
                . ' WHERE p.`einsatz_id` = ?',
            'i',
            $incidentId
        ) === count($sourceIdentities),
        'route identities multiplied across plan versions'
    );
    /*
     * Eine Zuordnung wird nie umgehaengt. Wer einen anderen Weg will,
     * schreibt einen anderen Eintrag; die Kennung eines bestehenden ist eine
     * Tatsache, keine Einstellung.
     */
    $mappingRepointed = false;
    $repoint = $connection->prepare(
        'UPDATE `nv_fernmeldeweg_zuordnung` SET `weg_id` = ?'
            . ' WHERE `fernmeldeplan_eintrag_id` = ?'
    );
    if (!$repoint) {
        throw new RuntimeException('Could not prepare the re-point probe');
    }
    try {
        $repoint->bind_param(
            'ii',
            $sourceIdentities[1][0],
            $sourceEntryIds[0]
        );
        $mappingRepointed = $repoint->execute();
    } catch (Throwable) {
        // Der Ausloeser estab_dv122_wegzuordnung_update weist genau das ab.
    } finally {
        $repoint->close();
    }
    $assert(
        !$mappingRepointed,
        'a route identity assignment could be re-pointed'
    );
    $revisionStartedEvent = $telecomPlanEvent(
        $connection,
        $incidentId,
        $revisionPlanId,
        'plan_revision_started'
    );
    $assert(
        ($revisionStartedEvent['details']['initial_state'] ?? null)
            === estab_dv_telecom_plan_header_audit_state($sourceAfterClone),
        'cloned telecommunications draft lacks its initial header snapshot'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_start_telecom_plan_revision(
            $connection,
            $incidentId,
            $planId,
            $s6Identity
        ),
        'a second parallel telecommunications draft was admitted'
    );
    $initialDraftRevision = (string) $draftAfterClone['revision'];
    $futureHeader = [
        'herkunft' => 'S6 Führungsstelle · zukünftige Folgefassung',
        'gueltig_ab' => date('Y-m-d H:i:s', time() + 3600),
        'gueltig_bis' => date('Y-m-d H:i:s', time() + 7200),
        'betriebsleitung' => 'LdF zukünftige Folgeversion',
        'bemerkungen' => 'Noch nicht gültiger Planentwurf',
    ];
    estab_dv_update_telecom_plan_draft(
        $connection,
        $incidentId,
        $revisionPlanId,
        $s6Identity,
        $futureHeader,
        $initialDraftRevision
    );
    $futureDraft = $telecomPlanById(
        $connection,
        $incidentId,
        $revisionPlanId
    );
    $futureUpdateEvent = $telecomPlanEvent(
        $connection,
        $incidentId,
        $revisionPlanId,
        'plan_draft_updated'
    );
    $assert(
        ($futureUpdateEvent['details']['before'] ?? null)
            === estab_dv_telecom_plan_header_audit_state($draftAfterClone)
            && ($futureUpdateEvent['details']['after'] ?? null)
                === estab_dv_telecom_plan_header_audit_state($futureDraft),
        'plan header update lacks exact before/after audit snapshots'
    );
    $invalidValidityMessage = '';
    try {
        estab_dv_activate_telecom_plan(
            $connection,
            $incidentId,
            $revisionPlanId,
            $s6Identity,
            'nv_protokoll',
            (string) $futureDraft['revision']
        );
    } catch (EstabDvConflictException $exception) {
        $invalidValidityMessage = $exception->getMessage();
    }
    $assert(
        str_contains($invalidValidityMessage, 'aktuellen Zeitpunkt nicht gültig')
            && (string) $scalar(
                $connection,
                'SELECT CONCAT(source_plan.`status`, ?, draft.`status`)'
                    . ' FROM `nv_fernmeldeplaene` AS source_plan'
                    . ' JOIN `nv_fernmeldeplaene` AS draft'
                    . ' ON draft.`fernmeldeplan_id` = ?'
                    . ' WHERE source_plan.`fernmeldeplan_id` = ?',
                'sii',
                '|',
                $revisionPlanId,
                $planId
            ) === 'AKTIV|ENTWURF',
        'future plan activation replaced the active plan or lacked guidance'
    );
    estab_dv_update_telecom_plan_draft(
        $connection,
        $incidentId,
        $revisionPlanId,
        $s6Identity,
        [
            'herkunft' => 'S6 Führungsstelle · Folgefassung',
            'gueltig_ab' => date('Y-m-d H:i:s', time() - 30),
            'gueltig_bis' => date('Y-m-d H:i:s', time() + 7200),
            'betriebsleitung' => 'LdF Folgeversion',
            'bemerkungen' => 'Bearbeiteter, vollständig kopierter Plan',
        ],
        (string) $futureDraft['revision']
    );
    $expect(
        EstabDvConflictException::class,
        static function () use (
            $connection,
            $incidentId,
            $revisionPlanId,
            $draftAfterClone,
            $s6Identity,
            $initialDraftRevision
        ): void {
            estab_dv_update_telecom_entry(
                $connection,
                $incidentId,
                $revisionPlanId,
                (int) $draftAfterClone['eintraege'][0]
                    ['fernmeldeplan_eintrag_id'],
                $s6Identity,
                [
                    'betriebsstelle' => 'Veraltete Änderung',
                    'rufname' => 'Veraltet 01',
                    'medium' => 'Fu',
                    'kanal' => '99',
                    'bandlage' => 'O/U',
                    'verkehrsform' => 'Gegenverkehr',
                    'besondere_vermerke' => '',
                    'bemerkungen' => '',
                ],
                $initialDraftRevision
            );
        },
        'a stale telecommunications editor overwrote a newer draft state'
    );
    $currentDraft = null;
    foreach (estab_dv_telecom_plans($connection, $incidentId) as $candidate) {
        if ((int) $candidate['fernmeldeplan_id'] === $revisionPlanId) {
            $currentDraft = $candidate;
            break;
        }
    }
    if (!is_array($currentDraft)) {
        throw new RuntimeException('Updated telecommunications draft missing');
    }
    $revisionEntryId = (int) $currentDraft['eintraege'][0]
        ['fernmeldeplan_eintrag_id'];
    estab_dv_update_telecom_entry(
        $connection,
        $incidentId,
        $revisionPlanId,
        $revisionEntryId,
        $s6Identity,
        [
            'betriebsstelle' => 'Gegenstelle Folgeversion',
            'rufname' => 'Integration 02',
            'medium' => 'Fu',
            'kanal' => 'TMO 404',
            'bandlage' => 'G/U',
            'verkehrsform' => 'Gegenverkehr',
            'besondere_vermerke' => 'Nur im Folgeplan',
            'bemerkungen' => 'Bearbeiteter übernommener Weg',
        ],
        (string) $currentDraft['revision']
    );
    $currentDraft = null;
    foreach (estab_dv_telecom_plans($connection, $incidentId) as $candidate) {
        if ((int) $candidate['fernmeldeplan_id'] === $revisionPlanId) {
            $currentDraft = $candidate;
            break;
        }
    }
    if (!is_array($currentDraft)) {
        throw new RuntimeException('Edited telecommunications draft missing');
    }
    $temporaryRouteId = estab_dv_add_telecom_entry(
        $connection,
        $incidentId,
        $revisionPlanId,
        $s6Identity,
        [
            'betriebsstelle' => 'Temporärer Fernsprecherweg',
            'rufname' => 'Integration Telefon',
            'medium' => 'Fe',
            'kanal' => 'Browser-Manipulation',
            'bandlage' => 'Browser-Manipulation',
            'verkehrsform' => 'Fernsprechverbindung',
            'besondere_vermerke' => '',
            'bemerkungen' => '',
        ],
        'nv_protokoll',
        (string) $currentDraft['revision']
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT CONCAT(`medium`, ?, `kanal`, ?, `bandlage`, ?,'
                . ' `verkehrsform`)'
                . ' FROM `nv_fernmeldeplan_eintraege`'
                . ' WHERE `fernmeldeplan_eintrag_id` = ?',
            'sssi',
            '|',
            '|',
            '|',
            $temporaryRouteId
        ) === 'Fe|||Fernsprechverbindung',
        'medium-inapplicable channel or band values survived server validation'
    );
    $currentDraft = $telecomPlanById(
        $connection,
        $incidentId,
        $revisionPlanId
    );
    $largeUtf8 = str_repeat('🚒', 10000);
    estab_dv_update_telecom_entry(
        $connection,
        $incidentId,
        $revisionPlanId,
        $temporaryRouteId,
        $s6Identity,
        [
            'betriebsstelle' => 'Temporärer Fernsprecherweg mit Langtext',
            'rufname' => 'Integration Langtext',
            'medium' => 'Fe',
            'verkehrsform' => 'Fernsprechverbindung',
            'besondere_vermerke' => $largeUtf8,
            'bemerkungen' => $largeUtf8,
        ],
        (string) $currentDraft['revision']
    );
    $largeUpdateEvent = $telecomEntryEvent(
        $connection,
        $incidentId,
        $revisionPlanId,
        $temporaryRouteId,
        'plan_entry_updated'
    );
    $largeUpdateProtocol = $latestTelecomProtocol(
        $connection,
        $incidentId
    );
    $largeUpdatePayload = json_encode(
        ['version' => 1] + $largeUpdateEvent['details'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
    );
    $assert(
        is_string($largeUpdatePayload)
            && ($largeUpdateEvent['details']['after']['besondere_vermerke']
                ?? null) === $largeUtf8
            && ($largeUpdateEvent['details']['after']['bemerkungen'] ?? null)
                === $largeUtf8
            && strlen($largeUpdatePayload) > 65535
            && strlen($largeUpdateProtocol['payload']) < 65535
            && ($largeUpdateProtocol['details']['full_details'] ?? null)
                === 'nv_betriebsereignisse'
            && ($largeUpdateProtocol['details']['action'] ?? null)
                === 'plan_entry_updated'
            && (int) ($largeUpdateProtocol['details']['object_id'] ?? 0)
                === $revisionPlanId
            && (int) ($largeUpdateProtocol['details']['event_sequence'] ?? 0)
                === $largeUpdateEvent['sequence']
            && ($largeUpdateProtocol['details']['event_hash'] ?? null)
                === $largeUpdateEvent['event_hash']
            && (int) ($largeUpdateProtocol['details']['details_bytes'] ?? 0)
                === strlen($largeUpdatePayload)
            && hash_equals(
                hash('sha256', $largeUpdatePayload),
                (string) (
                    $largeUpdateProtocol['details']['details_sha256'] ?? ''
                )
            ),
        'large UTF-8 update was truncated or lacked a compact legacy audit link'
    );
    $currentDraft = $telecomPlanById(
        $connection,
        $incidentId,
        $revisionPlanId
    );
    estab_dv_delete_telecom_entry(
        $connection,
        $incidentId,
        $revisionPlanId,
        $temporaryRouteId,
        $s6Identity,
        (string) $currentDraft['revision']
    );
    $largeDeleteEvent = $telecomEntryEvent(
        $connection,
        $incidentId,
        $revisionPlanId,
        $temporaryRouteId,
        'plan_entry_deleted'
    );
    $largeDeleteProtocol = $latestTelecomProtocol(
        $connection,
        $incidentId
    );
    $largeDeletePayload = json_encode(
        ['version' => 1] + $largeDeleteEvent['details'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
    );
    $assert(
        is_string($largeDeletePayload)
            && ($largeDeleteEvent['details']['before']['besondere_vermerke']
                ?? null) === $largeUtf8
            && ($largeDeleteEvent['details']['before']['bemerkungen'] ?? null)
                === $largeUtf8
            && strlen($largeDeletePayload) > 65535
            && strlen($largeDeleteProtocol['payload']) < 65535
            && ($largeDeleteProtocol['details']['full_details'] ?? null)
                === 'nv_betriebsereignisse'
            && ($largeDeleteProtocol['details']['action'] ?? null)
                === 'plan_entry_deleted'
            && (int) ($largeDeleteProtocol['details']['object_id'] ?? 0)
                === $revisionPlanId
            && (int) ($largeDeleteProtocol['details']['event_sequence'] ?? 0)
                === $largeDeleteEvent['sequence']
            && ($largeDeleteProtocol['details']['event_hash'] ?? null)
                === $largeDeleteEvent['event_hash']
            && (int) ($largeDeleteProtocol['details']['details_bytes'] ?? 0)
                === strlen($largeDeletePayload)
            && hash_equals(
                hash('sha256', $largeDeletePayload),
                (string) (
                    $largeDeleteProtocol['details']['details_sha256'] ?? ''
                )
            ),
        'large UTF-8 delete was truncated or lacked a compact legacy audit link'
    );
    unset($largeUtf8, $largeUpdatePayload, $largeDeletePayload);
    $currentDraft = null;
    foreach (estab_dv_telecom_plans($connection, $incidentId) as $candidate) {
        if ((int) $candidate['fernmeldeplan_id'] === $revisionPlanId) {
            $currentDraft = $candidate;
            break;
        }
    }
    if (!is_array($currentDraft)) {
        throw new RuntimeException('Final telecommunications draft missing');
    }
    // Simulate a fully valid draft created by the former blank-plan workflow
    // while version 1 was still active. Once the cloned successor below is
    // activated, this older basis must be rejected rather than replacing it.
    $staleLegacyDraft = $createLegacyTelecomDraft(
        $connection,
        $incidentId,
        $s6Identity,
        'vor aktueller Aktivierung'
    );
    estab_dv_activate_telecom_plan(
        $connection,
        $incidentId,
        $revisionPlanId,
        $s6Identity,
        'nv_protokoll',
        (string) $currentDraft['revision']
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT CONCAT(source_plan.`status`, ?, successor.`status`, ?,'
                . ' source_entry.`betriebsstelle`, ?, source_entry.`medium`, ?,'
                . ' source_entry.`kanal`, ?, source_entry.`bandlage`, ?,'
                . ' source_entry.`verkehrsform`)'
                . ' FROM `nv_fernmeldeplaene` AS source_plan'
                . ' JOIN `nv_fernmeldeplan_eintraege` AS source_entry'
                . ' ON source_entry.`fernmeldeplan_id` ='
                . ' source_plan.`fernmeldeplan_id`'
                . ' JOIN `nv_fernmeldeplaene` AS successor'
                . ' ON successor.`fernmeldeplan_id` = ?'
                . ' WHERE source_plan.`fernmeldeplan_id` = ?'
                . ' AND source_entry.`fernmeldeplan_eintrag_id` = ?',
            'ssssssiii',
            '|',
            '|',
            '|',
            '|',
            '|',
            '|',
            $revisionPlanId,
            $planId,
            $routeId
        ) === 'ERSETZT|AKTIV|Gegenstelle Integration|Me|||Melderbeförderung',
        'publishing the edited successor changed its immutable source version'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_resolve_active_route(
            $connection,
            $incidentId,
            $routeId,
            'Me'
        ),
        'a superseded route remained selectable for new dispositions'
    );
    $resolvedSuccessor = estab_dv_resolve_active_route(
        $connection,
        $incidentId,
        $revisionEntryId,
        'Fu'
    );
    $assert(
        (int) $resolvedSuccessor['version'] === $expectedRevisionVersion
            && $resolvedSuccessor['rufname'] === 'Integration 02',
        'edited successor route was not selected from the active revision'
    );

    $successorActivationSequence = (int) $scalar(
        $connection,
        'SELECT `sequenz` FROM `nv_betriebsereignisse`'
            . ' WHERE `einsatz_id` = ?'
            . " AND `objekttyp` = 'FERNMELDEPLAN'"
            . ' AND `objekt_id` = ?'
            . " AND `aktion` = 'plan_activated'"
            . ' ORDER BY `sequenz` DESC LIMIT 1',
        'ii',
        $incidentId,
        $revisionPlanId
    );
    $staleLegacyPlanId = (int) $staleLegacyDraft['fernmeldeplan_id'];
    $staleLegacyEntryId = (int) $staleLegacyDraft[
        'fernmeldeplan_eintrag_id'
    ];
    $staleLegacyCreationSequence = (int) $scalar(
        $connection,
        'SELECT `sequenz` FROM `nv_betriebsereignisse`'
            . ' WHERE `einsatz_id` = ?'
            . " AND `objekttyp` = 'FERNMELDEPLAN'"
            . ' AND `objekt_id` = ?'
            . " AND `aktion` = 'plan_created'"
            . ' ORDER BY `sequenz` LIMIT 1',
        'ii',
        $incidentId,
        $staleLegacyPlanId
    );
    $staleLegacyPlan = $telecomPlanById(
        $connection,
        $incidentId,
        $staleLegacyPlanId
    );
    $assert(
        (int) $staleLegacyDraft['version']
            === $expectedRevisionVersion + 1
            && $staleLegacyCreationSequence > 0
            && $staleLegacyCreationSequence < $successorActivationSequence,
        'stale legacy draft fixture was not created before current activation'
    );
    $expect(
        EstabDvConflictException::class,
        static function () use (
            $connection,
            $incidentId,
            $staleLegacyPlanId,
            $s6Identity,
            $staleLegacyPlan
        ): void {
            estab_dv_activate_telecom_plan(
                $connection,
                $incidentId,
                $staleLegacyPlanId,
                $s6Identity,
                'nv_protokoll',
                (string) $staleLegacyPlan['revision']
            );
        },
        'legacy draft created before the current activation replaced that plan'
    );
    estab_dv_discard_telecom_plan_draft(
        $connection,
        $incidentId,
        $staleLegacyPlanId,
        $s6Identity,
        (string) $staleLegacyPlan['revision']
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT CONCAT(p.`status`, ?, p.`freigegeben_am` IS NULL, ?, '
                . 'p.`freigegeben_von` IS NULL, ?, COUNT(e.`fernmeldeplan_eintrag_id`))'
                . ' FROM `nv_fernmeldeplaene` AS p'
                . ' LEFT JOIN `nv_fernmeldeplan_eintraege` AS e'
                . ' ON e.`fernmeldeplan_id` = p.`fernmeldeplan_id`'
                . ' WHERE p.`fernmeldeplan_id` = ?'
                . ' GROUP BY p.`fernmeldeplan_id`, p.`status`,'
                . ' p.`freigegeben_am`, p.`freigegeben_von`',
            'sssi',
            '|',
            '|',
            '|',
            $staleLegacyPlanId
        ) === 'ERSETZT|1|1|1'
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_betriebsereignisse`'
                    . ' WHERE `einsatz_id` = ?'
                    . " AND `objekttyp` = 'FERNMELDEPLAN'"
                    . ' AND `objekt_id` = ?'
                    . " AND `aktion` = 'plan_draft_discarded'"
                    . ' AND BINARY `akteur_kuerzel` = BINARY ?'
                    . ' AND BINARY `akteur_funktion` = BINARY ?'
                    . ' AND CAST(JSON_UNQUOTE(JSON_EXTRACT('
                    . " `details`, '$.preserved_entries')) AS UNSIGNED) = 1"
                    . ' AND CAST(JSON_UNQUOTE(JSON_EXTRACT('
                    . " `details`, '$.plan_version')) AS UNSIGNED) = ?",
                'iissi',
                $incidentId,
                $staleLegacyPlanId,
                $s6Identity['kuerzel'],
                $s6Identity['funktion'],
                $expectedRevisionVersion + 1
            ) === 1,
        'discarded draft lost its entries, release state or immutable audit'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $staleLegacyPlanId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_fernmeldeplaene` SET `bemerkungen` = ?'
                    . ' WHERE `fernmeldeplan_id` = ?'
            );
            try {
                $forgedNotes = 'Verworfenen Plan nachträglich verändert';
                $statement->bind_param(
                    'si',
                    $forgedNotes,
                    $staleLegacyPlanId
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'discarded telecommunications plan remained mutable'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $staleLegacyEntryId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_fernmeldeplan_eintraege` SET `rufname` = ?'
                    . ' WHERE `fernmeldeplan_eintrag_id` = ?'
            );
            try {
                $forgedCallSign = 'Verworfen manipuliert';
                $statement->bind_param(
                    'si',
                    $forgedCallSign,
                    $staleLegacyEntryId
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'entry of a discarded telecommunications plan remained mutable'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $staleLegacyEntryId): void {
            $statement = $connection->prepare(
                'DELETE FROM `nv_fernmeldeplan_eintraege`'
                    . ' WHERE `fernmeldeplan_eintrag_id` = ?'
            );
            try {
                $statement->bind_param('i', $staleLegacyEntryId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'entry of a discarded telecommunications plan could be deleted'
    );

    $replacementDraft = estab_dv_start_telecom_plan_revision(
        $connection,
        $incidentId,
        $revisionPlanId,
        $s6Identity
    );
    $replacementDraftId = (int) $replacementDraft['fernmeldeplan_id'];
    $replacementDraftPlan = $telecomPlanById(
        $connection,
        $incidentId,
        $replacementDraftId
    );
    $assert(
        (int) $replacementDraft['version']
            === $expectedRevisionVersion + 2
            && (int) $replacementDraft['source_plan_id'] === $revisionPlanId
            && (int) $replacementDraft['copied_entries'] === 2
            && $replacementDraftPlan['status'] === 'ENTWURF',
        'discarded draft continued to block a fresh clone of the active plan'
    );
    estab_dv_discard_telecom_plan_draft(
        $connection,
        $incidentId,
        $replacementDraftId,
        $s6Identity,
        (string) $replacementDraftPlan['revision']
    );

    $currentLegacyDraft = $createLegacyTelecomDraft(
        $connection,
        $incidentId,
        $s6Identity,
        'nach aktueller Aktivierung'
    );
    $currentLegacyPlanId = (int) $currentLegacyDraft['fernmeldeplan_id'];
    $currentLegacyPlan = $telecomPlanById(
        $connection,
        $incidentId,
        $currentLegacyPlanId
    );
    $currentLegacyCreationSequence = (int) $scalar(
        $connection,
        'SELECT `sequenz` FROM `nv_betriebsereignisse`'
            . ' WHERE `einsatz_id` = ?'
            . " AND `objekttyp` = 'FERNMELDEPLAN'"
            . ' AND `objekt_id` = ?'
            . " AND `aktion` = 'plan_created'"
            . ' ORDER BY `sequenz` LIMIT 1',
        'ii',
        $incidentId,
        $currentLegacyPlanId
    );
    $assert(
        (int) $currentLegacyDraft['version']
            === $expectedRevisionVersion + 3
            && $currentLegacyCreationSequence > $successorActivationSequence,
        'current legacy draft fixture was not created after active-plan release'
    );
    estab_dv_activate_telecom_plan(
        $connection,
        $incidentId,
        $currentLegacyPlanId,
        $s6Identity,
        'nv_protokoll',
        (string) $currentLegacyPlan['revision']
    );
    $currentLegacyRoute = estab_dv_resolve_active_route(
        $connection,
        $incidentId,
        (int) $currentLegacyDraft['fernmeldeplan_eintrag_id'],
        'Fu'
    );
    $assert(
        (int) $currentLegacyRoute['version']
            === $expectedRevisionVersion + 3
            && (int) $currentLegacyRoute['fernmeldeplan_id']
                === $currentLegacyPlanId
            && (string) $scalar(
                $connection,
                'SELECT `status` FROM `nv_fernmeldeplaene`'
                    . ' WHERE `fernmeldeplan_id` = ?',
                'i',
                $revisionPlanId
            ) === 'ERSETZT',
        'eligible post-activation legacy draft could not become the successor'
    );

    $storedS2 = estab_auth_fetch_session_user(
        $connection,
        'nv_benutzer',
        $codes['s2']
    );
    $assert(
        is_array($storedS2)
            && estab_auth_account_matches_session(
                $storedS2,
                $s2Identity,
                $phpSessionId
            ),
        'fixed S2 account session was invalid before historical handover'
    );
    // Take a fresh draft snapshot immediately before the real handover. The
    // earlier activation of the separate LOOSE matrix incident deliberately
    // advanced the global status revision and must not mask the assignment-id
    // assertion.
    estab_permission_context_set_from_incident(
        estab_incident_status($connection)
    );
    $strictAttachmentContext = estab_attachment_origin_context_create(
        $s2Identity + [
            'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
        ],
        $incidentId,
        ['task' => 'Stab_schreiben', '00_lfd' => '']
    );
    $cancelledHandoverRequestId = estab_dv_initiate_handover_shift(
        $connection,
        $incidentId,
        $shiftId,
        $secondShiftId,
        'Vollständige Lage-, Nachrichten- und Auftragsübergabe.',
        $assignments['s2'],
        $s2Identity,
        $actor
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_dienstschichten`'
                . " WHERE `einsatz_id` = ? AND `status` = 'AKTIV'",
            'i',
            $incidentId
        ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_dienstuebergaben`'
                    . ' WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === 0,
        'admin initiation falsely claimed or executed successor confirmation'
    );
    estab_dv_cancel_handover_request(
        $connection,
        $incidentId,
        $cancelledHandoverRequestId,
        'Falsche Übergabezusammenfassung gewählt.',
        $actor
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT `status` FROM `nv_dienstuebergabe_anfragen`'
                . ' WHERE `dienstuebergabe_anfrage_id` = ?',
            'i',
            $cancelledHandoverRequestId
        ) === 'STORNIERT',
        'an erroneous handover request was not retained as cancelled evidence'
    );
    $handoverRequestId = estab_dv_initiate_handover_shift(
        $connection,
        $incidentId,
        $shiftId,
        $secondShiftId,
        'Vollständige Lage-, Nachrichten- und Auftragsübergabe.',
        $assignments['s2'],
        $s2Identity,
        $actor
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_confirm_handover_shift(
                $connection,
                $incidentId,
                $handoverRequestId,
                $secondAssignments['s2'],
                [
                    'benutzer' => 'Zweiter Sichter',
                    'kuerzel' => $codes['si_duplicate'],
                    'funktion' => 'Si',
                    'rolle' => 'Stab',
                ]
            ) ?? null
        ),
        'an account outside the successor assignment confirmed the handover'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_confirm_handover_shift(
                $connection,
                $incidentId,
                $handoverRequestId,
                $secondAssignments['s2'],
                [
                    'benutzer' => 'Nachfolgende Lage/Dokumentation',
                    'kuerzel' => $codes['s2_successor'],
                    'funktion' => 'S2',
                    'rolle' => 'Stab',
                ]
            ) ?? null
        ),
        'offline successor account confirmed the current handover action'
    );
    $online = 1;
    $setSuccessorOnline->bind_param(
        'is',
        $online,
        $codes['s2_successor']
    );
    $setSuccessorOnline->execute();
    $setSuccessorOnline->close();
    $etbCountBeforeHandover = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $tbbCountBeforeHandover = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $etbLastBeforeHandover = (int) $scalar(
        $connection,
        'SELECT MAX(`estab_book_lfd`) FROM `nv_etb` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $tbbLastBeforeHandover = (int) $scalar(
        $connection,
        'SELECT MAX(`estab_book_lfd`) FROM `nv_tbb` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $incomingHandoverIdentity = [
        'benutzer' => 'Nachfolgende Lage/Dokumentation',
        'kuerzel' => $codes['s2_successor'],
        'funktion' => 'S2',
        'rolle' => 'Stab',
    ];
    $handoverRollbackEvidence = $logbookEvidenceSnapshot(
        $connection,
        $incidentId
    );
    $handoverRollbackState = $handoverStateSnapshot(
        $connection,
        $incidentId,
        $handoverRequestId
    );
    $handoverCompletionProbe = estab_dv_database_now($connection);
    $handoverCompletionTime = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s.u',
        $handoverCompletionProbe
    );
    if (!$handoverCompletionTime instanceof DateTimeImmutable) {
        throw new RuntimeException('Could not parse handover completion probe');
    }
    $inconsistentCompletionProbe = $handoverCompletionTime
        ->modify('+1 second')
        ->format('Y-m-d H:i:s.u');
    $handoverSummary = (string) $scalar(
        $connection,
        'SELECT `zusammenfassung` FROM `nv_dienstuebergabe_anfragen`'
            . ' WHERE `dienstuebergabe_anfrage_id` = ?',
        'i',
        $handoverRequestId
    );
    $transitionProbeShifts = static function (
        mysqli $database,
        int $oldShiftId,
        int $newShiftId,
        string $completedAt
    ): void {
        $closeProbe = $database->prepare(
            "UPDATE `nv_dienstschichten` SET `status` = 'UEBERGEBEN',"
                . ' `beendet_am` = ? WHERE `dienstschicht_id` = ?'
                . " AND `status` = 'AKTIV'"
        );
        $activateProbe = $database->prepare(
            "UPDATE `nv_dienstschichten` SET `status` = 'AKTIV',"
                . ' `aktiviert_am` = ? WHERE `dienstschicht_id` = ?'
                . " AND `status` = 'GEPLANT'"
        );
        if (!$closeProbe || !$activateProbe) {
            $closeProbe?->close();
            $activateProbe?->close();
            throw new RuntimeException('Could not prepare handover time probe');
        }
        try {
            $closeProbe->bind_param('si', $completedAt, $oldShiftId);
            $activateProbe->bind_param('si', $completedAt, $newShiftId);
            $closeProbe->execute();
            $activateProbe->execute();
        } finally {
            $closeProbe->close();
            $activateProbe->close();
        }
    };
    $connection->begin_transaction();
    try {
        $transitionProbeShifts(
            $connection,
            $shiftId,
            $secondShiftId,
            $handoverCompletionProbe
        );
        $expect(
            mysqli_sql_exception::class,
            static function () use (
                $connection,
                $incidentId,
                $shiftId,
                $secondShiftId,
                $handoverSummary,
                $inconsistentCompletionProbe,
                $codes
            ): void {
                $statement = $connection->prepare(
                    'INSERT INTO `nv_dienstuebergaben`'
                        . ' (`einsatz_id`, `von_dienstschicht_id`,'
                        . ' `an_dienstschicht_id`, `zusammenfassung`,'
                        . ' `uebergeben_am`, `uebergeben_von`,'
                        . ' `angenommen_von`) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                try {
                    $statement->bind_param(
                        'iiissss',
                        $incidentId,
                        $shiftId,
                        $secondShiftId,
                        $handoverSummary,
                        $inconsistentCompletionProbe,
                        $codes['s2'],
                        $codes['s2_successor']
                    );
                    $statement->execute();
                } finally {
                    $statement->close();
                }
            },
            'database accepted contradictory completed-handover times'
        );
    } finally {
        $connection->rollback();
    }
    $connection->begin_transaction();
    try {
        $transitionProbeShifts(
            $connection,
            $shiftId,
            $secondShiftId,
            $handoverCompletionProbe
        );
        $insertProbe = $connection->prepare(
            'INSERT INTO `nv_dienstuebergaben`'
                . ' (`einsatz_id`, `von_dienstschicht_id`,'
                . ' `an_dienstschicht_id`, `zusammenfassung`,'
                . ' `uebergeben_am`, `uebergeben_von`, `angenommen_von`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $insertProbe->bind_param(
                'iiissss',
                $incidentId,
                $shiftId,
                $secondShiftId,
                $handoverSummary,
                $handoverCompletionProbe,
                $codes['s2'],
                $codes['s2_successor']
            );
            $insertProbe->execute();
            $probeHandoverId = (int) $connection->insert_id;
        } finally {
            $insertProbe->close();
        }
        $expect(
            mysqli_sql_exception::class,
            static function () use (
                $connection,
                $handoverRequestId,
                $secondAssignments,
                $codes,
                $probeHandoverId,
                $inconsistentCompletionProbe
            ): void {
                $statement = $connection->prepare(
                    'UPDATE `nv_dienstuebergabe_anfragen`'
                        . " SET `status` = 'BESTAETIGT',"
                        . ' `bestaetigt_am` = ?, `bestaetigt_von` = ?,'
                        . ' `bestaetigt_mit_besetzung_id` = ?,'
                        . ' `dienstuebergabe_id` = ?'
                        . ' WHERE `dienstuebergabe_anfrage_id` = ?'
                );
                try {
                    $statement->bind_param(
                        'ssiii',
                        $inconsistentCompletionProbe,
                        $codes['s2_successor'],
                        $secondAssignments['s2'],
                        $probeHandoverId,
                        $handoverRequestId
                    );
                    $statement->execute();
                } finally {
                    $statement->close();
                }
            },
            'database accepted contradictory handover confirmation times'
        );
    } finally {
        $connection->rollback();
    }
    $assert(
        $logbookEvidenceSnapshot($connection, $incidentId)
            === $handoverRollbackEvidence
            && $handoverStateSnapshot(
                $connection,
                $incidentId,
                $handoverRequestId
            ) === $handoverRollbackState,
        'rejected direct handover time contradictions changed durable evidence'
    );
    $connection->query(
        'DROP TRIGGER IF EXISTS `estab_test_handover_audit_failure`'
    );
    $connection->query(
        'CREATE TRIGGER `estab_test_handover_audit_failure`'
        . ' BEFORE INSERT ON `nv_protokoll` FOR EACH ROW'
        . ' BEGIN IF @estab_test_fail_handover = 1'
        . " AND BINARY NEW.`p_was` = BINARY 'DV Übergabe' THEN"
        . " SIGNAL SQLSTATE '45000'"
        . " SET MESSAGE_TEXT = 'injected handover audit failure';"
        . ' END IF; END'
    );
    try {
        $connection->query('SET @estab_test_fail_handover = 1');
        $expect(
            mysqli_sql_exception::class,
            static fn (): null => (
                estab_dv_confirm_handover_shift(
                    $connection,
                    $incidentId,
                    $handoverRequestId,
                    $secondAssignments['s2'],
                    $incomingHandoverIdentity
                ) ?? null
            ),
            'an injected final handover-audit failure did not abort confirmation'
        );
    } finally {
        $connection->query('SET @estab_test_fail_handover = NULL');
        $connection->query(
            'DROP TRIGGER `estab_test_handover_audit_failure`'
        );
    }
    $assert(
        $logbookEvidenceSnapshot($connection, $incidentId)
            === $handoverRollbackEvidence
            && $handoverStateSnapshot(
                $connection,
                $incidentId,
                $handoverRequestId
            ) === $handoverRollbackState
            && str_starts_with(
                (string) ($handoverRollbackState['request'] ?? ''),
                'INITIIERT:'
            ),
        'failed handover retained partial shifts, assignments, request, '
            . 'handover, ETB/TBB, heads or audit mutations'
    );
    $strictReadConnection = estab_auth_connect($databaseConfig);
    $handoverMutationConnection = estab_auth_connect($databaseConfig);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $strictReadTransaction = false;
    try {
        $handoverMutationConnection->query(
            'SET SESSION innodb_lock_wait_timeout = 1'
        );
        $strictReadConnection->begin_transaction();
        $strictReadTransaction = true;
        $strictReadScope = estab_read_require_operational_scope(
            $strictReadConnection,
            $s2Identity,
            true
        );
        $strictProtectedMessage = $scalar(
            $strictReadConnection,
            'SELECT `00_lfd` FROM `nv_nachrichten`'
                . ' WHERE `einsatz_id` = ? AND `00_lfd` = ?',
            'ii',
            $incidentId,
            $messageId
        );
        $expectLockWaitTimeout(
            static fn (): null => (
                estab_dv_confirm_handover_shift(
                    $handoverMutationConnection,
                    $incidentId,
                    $handoverRequestId,
                    $secondAssignments['s2'],
                    $incomingHandoverIdentity
                ) ?? null
            ),
            'STRICT handover passed an in-flight protected read'
        );
        $assert(
            (int) $strictProtectedMessage === $messageId
                && ($strictReadScope['identity']['duty_assignment_id'] ?? 0)
                    === $assignments['s2'],
            'STRICT assignment-protected object was not selected before release'
        );
        $strictReadConnection->commit();
        $strictReadTransaction = false;

        estab_dv_confirm_handover_shift(
            $handoverMutationConnection,
            $incidentId,
            $handoverRequestId,
            $secondAssignments['s2'],
            $incomingHandoverIdentity
        );
        $expect(
            EstabReadPermissionException::class,
            static fn (): array => estab_read_require_operational_scope(
                $strictReadConnection,
                $s2Identity
            ),
            'STRICT retained the outgoing assignment after handover'
        );
    } finally {
        if ($strictReadTransaction) {
            $strictReadConnection->rollback();
        }
        estab_auth_close($handoverMutationConnection);
        estab_auth_close($strictReadConnection);
    }
    $handoverInitiatedAt = (string) $scalar(
        $connection,
        "SELECT DATE_FORMAT(`initiiert_am`, '%Y-%m-%d %H:%i:%s.%f')"
            . ' FROM `nv_dienstuebergabe_anfragen`'
            . ' WHERE `dienstuebergabe_anfrage_id` = ?',
        'i',
        $handoverRequestId
    );
    $handoverTakenOverAt = (string) $scalar(
        $connection,
        "SELECT DATE_FORMAT(`bestaetigt_am`, '%Y-%m-%d %H:%i:%s.%f')"
            . ' FROM `nv_dienstuebergabe_anfragen`'
            . ' WHERE `dienstuebergabe_anfrage_id` = ?',
        'i',
        $handoverRequestId
    );
    $handoverInitiatedDisplay = estab_logbook_lifecycle_display_timestamp(
        $handoverInitiatedAt
    );
    $handoverTakenOverDisplay = estab_logbook_lifecycle_display_timestamp(
        $handoverTakenOverAt
    );
    $activeEtbAcceptanceEvidence = $logbookEvidenceSnapshot(
        $connection,
        $incidentId
    );
    $activeEtbAcceptanceRejection = $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_accept_hat(
            $connection,
            $incidentId,
            $secondAssignments['etb'],
            $codes['si']
        ),
        'planned ETB assignment displaced S2 after its shift became active'
    );
    $assert(
        str_contains(
            $activeEtbAcceptanceRejection->getMessage(),
            'dokumentierte und bestätigte Schichtübergabe'
        )
            && (string) $scalar(
                $connection,
                'SELECT `status` FROM `nv_dienstbesetzungen`'
                    . ' WHERE `dienstbesetzung_id` = ?',
                'i',
                $secondAssignments['etb']
            ) === 'ZUGEWIESEN'
            && $logbookEvidenceSnapshot($connection, $incidentId)
                === $activeEtbAcceptanceEvidence,
        'rejected active-shift ETB acceptance changed assignment or evidence'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ) === $etbCountBeforeHandover + 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === $tbbCountBeforeHandover + 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_book_lfd` = ?'
                    . " AND `etb_aktion` LIKE '%Dienstübergabe%'"
                    . " AND `etb_aktion` LIKE CONCAT('%Letzte ETB-Nr. vor der Übergabe: ', ?, '.%')",
                'iii',
                $incidentId,
                $etbLastBeforeHandover + 1,
                $etbLastBeforeHandover
            ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_book_lfd` = ?'
                    . " AND `estab_personnel_duty` LIKE '%Dienstübergabe%'"
                    . " AND `estab_personnel_duty` LIKE CONCAT('%Letzte TBB-Nr. vor der Übergabe: ', ?, '.%')",
                'iii',
                $incidentId,
                $tbbLastBeforeHandover + 1,
                $tbbLastBeforeHandover
            ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_book_lfd` = ?'
                    . " AND `etb_aktion` LIKE '%Zweiter Leiter FmZt%'",
                'ii',
                $incidentId,
                $etbLastBeforeHandover + 1
            ) === 0
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_book_lfd` = ?'
                    . " AND `estab_personnel_duty` LIKE '%Zweiter Leiter FmZt%'",
                'ii',
                $incidentId,
                $tbbLastBeforeHandover + 1
            ) === 0
            && (string) $scalar(
                $connection,
                'SELECT `status` FROM `nv_dienstbesetzungen`'
                    . ' WHERE `dienstbesetzung_id` = ?',
                'i',
                $unacceptedOutgoingAssignmentId
            ) === 'ZURUECKGEZOGEN'
            && (string) $scalar(
                $connection,
                'SELECT `status` FROM `nv_dienstbesetzungen`'
                    . ' WHERE `dienstbesetzung_id` = ?',
                'i',
                $unacceptedSuccessorAssignmentId
            ) === 'ZUGEWIESEN',
        'confirmed shift handover was not appended atomically to both logbooks'
    );
    $handoverEtbText = (string) $scalar(
        $connection,
        'SELECT `etb_aktion` FROM `nv_etb` WHERE `einsatz_id` = ?'
            . ' AND `estab_book_lfd` = ?',
        'ii',
        $incidentId,
        $etbLastBeforeHandover + 1
    );
    $handoverTbbText = (string) $scalar(
        $connection,
        'SELECT `estab_personnel_duty` FROM `nv_tbb`'
            . ' WHERE `einsatz_id` = ? AND `estab_book_lfd` = ?',
        'ii',
        $incidentId,
        $tbbLastBeforeHandover + 1
    );
    $assert(
        $handoverInitiatedAt !== ''
            && $handoverTakenOverAt !== ''
            && strcmp($handoverInitiatedAt, $handoverTakenOverAt) <= 0
            && str_contains(
                $handoverEtbText,
                'Persönlich übergeben von'
            )
            && str_contains(
                $handoverEtbText,
                '[' . $codes['s2'] . '] um ' . $handoverInitiatedDisplay
            )
            && str_contains($handoverEtbText, $handoverInitiatedDisplay)
            && str_contains(
                $handoverEtbText,
                'persönlich übernommen von'
            )
            && str_contains(
                $handoverEtbText,
                '[' . $codes['s2_successor'] . '] um '
                    . $handoverTakenOverDisplay
            )
            && str_contains($handoverEtbText, $handoverTakenOverDisplay)
            && str_contains(
                $handoverTbbText,
                '[' . $codes['s2'] . '] um ' . $handoverInitiatedDisplay
            )
            && str_contains(
                $handoverTbbText,
                '[' . $codes['s2_successor'] . '] um '
                    . $handoverTakenOverDisplay
            )
            && !str_contains(
                $handoverEtbText . $handoverTbbText,
                'keine dokumentierte Besetzung'
            )
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_dienstuebergabe_anfragen` AS request'
                    . ' JOIN `nv_dienstuebergaben` AS handover'
                    . ' ON handover.`dienstuebergabe_id` ='
                    . ' request.`dienstuebergabe_id`'
                    . ' JOIN `nv_dienstschichten` AS old_shift'
                    . ' ON old_shift.`dienstschicht_id` ='
                    . ' request.`von_dienstschicht_id`'
                    . ' JOIN `nv_dienstschichten` AS new_shift'
                    . ' ON new_shift.`dienstschicht_id` ='
                    . ' request.`an_dienstschicht_id`'
                    . ' WHERE request.`dienstuebergabe_anfrage_id` = ?'
                    . ' AND request.`bestaetigt_am` = handover.`uebergeben_am`'
                    . ' AND request.`bestaetigt_am` = old_shift.`beendet_am`'
                    . ' AND request.`bestaetigt_am` = new_shift.`aktiviert_am`',
                'i',
                $handoverRequestId
            ) === 1,
        'handover and takeover persons or their separate times are missing'
    );
    $assert(
        !estab_auth_duty_assignment_matches_session(
            $connection,
            $assignments['s2'],
            $codes['s2'],
            'S2',
            'Stab'
        )
            && estab_auth_duty_assignment_matches_session(
                $connection,
                $secondAssignments['s2'],
                $codes['s2_successor'],
                'S2',
                'Stab'
            ),
        'STRICT session validation retained the handed-over assignment or '
            . 'rejected its active accepted successor'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            estab_attachment_origin_authority_identity(
                $strictAttachmentContext
            )
        ),
        'STRICT attachment authority survived the real shift handover'
    );
    estab_permission_context_set_from_incident(
        estab_incident_status($connection)
    );
    $sameAccountSuccessorTuple = $preShiftS2Identity + [
        'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
        'duty_assignment_id' => $secondAssignments['s2'],
    ];
    $expect(
        EstabAttachmentContextException::class,
        static fn (): array => estab_attachment_origin_context_validate(
            $strictAttachmentContext,
            $sameAccountSuccessorTuple,
            $incidentId
        ),
        'STRICT attachment draft survived an assignment change with the '
            . 'same account and function tuple'
    );
    $storedOldS2 = estab_auth_fetch_session_user(
        $connection,
        'nv_benutzer',
        $codes['s2']
    );
    $storedSuccessorS2 = estab_auth_fetch_session_user(
        $connection,
        'nv_benutzer',
        $codes['s2_successor']
    );
    $successorS2Identity = [
        'benutzer' => 'Nachfolgende Lage/Dokumentation',
        'kuerzel' => $codes['s2_successor'],
        'funktion' => 'S2',
        'rolle' => 'Stab',
    ];
    $assert(
        is_array($storedOldS2)
            && is_array($storedSuccessorS2)
            && estab_auth_account_matches_session(
                $storedOldS2,
                $s2Identity,
                $phpSessionId
            )
            && estab_auth_account_matches_session(
                $storedSuccessorS2,
                $successorS2Identity,
                'dv-session-' . $suffix . '-' . $codes['s2_successor']
            ),
        'historical handover changed or revoked fixed account identities'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT old_hat.`nachfolger_id`'
                . ' FROM `nv_dienstbesetzungen` AS old_hat'
                . ' JOIN `nv_dienstbesetzungen` AS successor_hat'
                . ' ON successor_hat.`dienstbesetzung_id` ='
                . ' old_hat.`nachfolger_id`'
                . ' WHERE old_hat.`dienstbesetzung_id` = ?'
                . ' AND successor_hat.`dienstbesetzung_id` = ?'
                . ' AND BINARY old_hat.`benutzer_kuerzel` <>'
                . ' BINARY successor_hat.`benutzer_kuerzel`'
                . ' AND BINARY old_hat.`funktion` ='
                . ' BINARY successor_hat.`funktion`'
                . ' AND BINARY old_hat.`rolle` ='
                . ' BINARY successor_hat.`rolle`',
            'ii',
            $assignments['s2'],
            $secondAssignments['s2']
        ) === $secondAssignments['s2'],
        'personnel handover did not link a different successor account'
    );
    $handoverReadModel = estab_dv_shift_list($connection, $incidentId);
    $assert(
        count($handoverReadModel) === 2
            && $handoverReadModel[0]['status'] === 'AKTIV'
            && $handoverReadModel[1]['status'] === 'UEBERGEBEN',
        'duty-shift UI read model did not retain handover history'
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $shiftId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_dienstschichten` SET `bezeichnung` = ?'
                . ' WHERE `dienstschicht_id` = ?'
            );
            $forgedLabel = 'Nachträglich manipulierte Schicht';
            try {
                $statement->bind_param('si', $forgedLabel, $shiftId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'terminal duty shift evidence label was mutable'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $assignments): void {
            $statement = $connection->prepare(
                'UPDATE `nv_dienstbesetzungen`'
                . ' SET `angenommen_am` = DATE_ADD(`angenommen_am`,'
                . ' INTERVAL 1 SECOND)'
                . ' WHERE `dienstbesetzung_id` = ?'
            );
            try {
                $statement->bind_param('i', $assignments['s2']);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'terminal duty assignment acceptance evidence was mutable'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use (
            $connection,
            $cancelledHandoverRequestId
        ): void {
            $statement = $connection->prepare(
                'UPDATE `nv_dienstuebergabe_anfragen`'
                . ' SET `stornierungsgrund` = ?'
                . ' WHERE `dienstuebergabe_anfrage_id` = ?'
            );
            $forgedReason = 'Nachträglich umgedeutet';
            try {
                $statement->bind_param(
                    'si',
                    $forgedReason,
                    $cancelledHandoverRequestId
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'cancelled handover request evidence was mutable'
    );

    $chain = estab_dv_verify_event_chain($connection, $incidentId);
    $assert(
        $chain['valid'] === true && (int) $chain['events'] >= 35,
        'command-post event hash chain is incomplete or invalid'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $incidentId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_betriebsereignisse` SET `aktion` = ?'
                . ' WHERE `einsatz_id` = ? AND `sequenz` = 1'
            );
            $tamperedAction = 'tampered';
            try {
                $statement->bind_param(
                    'si',
                    $tamperedAction,
                    $incidentId
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'command-post event ledger allowed an update'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $incidentId): void {
            $statement = $connection->prepare(
                'DELETE FROM `nv_betriebsereignisse`'
                . ' WHERE `einsatz_id` = ? AND `sequenz` = 1'
            );
            try {
                $statement->bind_param('i', $incidentId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'command-post event ledger allowed a delete'
    );

    $connection->begin_transaction();
    try {
        $openStatus = $connection->prepare(
            'UPDATE `nv_nachrichten` SET `x00_status` = 4'
                . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
        );
        if (!$openStatus) {
            throw new RuntimeException(
                'Could not prepare final-shift open-message fixture'
            );
        }
        try {
            $openStatus->bind_param('ii', $messageId, $incidentId);
            $openStatus->execute();
        } finally {
            $openStatus->close();
        }
        estab_message_event_append(
            $connection,
            $incidentId,
            $messageId,
            'dv_operations_fixture_open',
            [
                'benutzer' => 'Sichter',
                'kuerzel' => $codes['si'],
                'funktion' => 'Si',
            ],
            2,
            4,
            ['fixture' => 'Open status-4 closure blocker']
        );
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    $expect(
        EstabDvConflictException::class,
        static fn (): null => (
            estab_dv_close_shift(
                $connection,
                $incidentId,
                $secondShiftId,
                $actor
            ) ?? null
        ),
        'final active shift closed while a status-4 message was open'
    );
    $connection->begin_transaction();
    try {
        $terminalStatus = $connection->prepare(
            'UPDATE `nv_nachrichten`'
                . " SET `x00_status` = 8, `x01_abschluss` = 't'"
                . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
        );
        if (!$terminalStatus) {
            throw new RuntimeException(
                'Could not prepare final-shift terminal message fixture'
            );
        }
        try {
            $terminalStatus->bind_param('ii', $messageId, $incidentId);
            $terminalStatus->execute();
        } finally {
            $terminalStatus->close();
        }
        estab_message_event_append(
            $connection,
            $incidentId,
            $messageId,
            'dv_operations_fixture_completed',
            [
                'benutzer' => 'Sichter',
                'kuerzel' => $codes['si'],
                'funktion' => 'Si',
            ],
            4,
            8,
            ['fixture' => 'Completed before final shift close']
        );
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    $strictClosurePreflight = estab_incident_close_preflight(
        $connection,
        $incidentId
    );
    $assert(
        $strictClosurePreflight['closable'] === false
            && $strictClosurePreflight['offene_schichten'] > 0
            && $strictClosurePreflight['offene_besetzungen'] > 0,
        'STRICT ignored open formal shift and assignment records at closure'
    );
    // A separate incident created as LOOSE models retained formal records from
    // imported history. They remain visible evidence, but are not operational
    // prerequisites or closure blockers in this mode. Keep the fixture in
    // valid open planning states instead of bypassing the immutable transition
    // evidence enforced by the database triggers.
    $strictClosureStatus = estab_incident_status($connection);
    $looseClosureCreated = estab_incident_create(
        $connection,
        [
            'kennung' => 'DV-CLOSE-LOOSE-' . strtoupper($suffix),
            'name' => 'DV Abschlussprüfung LOOSE',
            'beginn' => date('Y-m-d\TH:i', time() - 900),
            'ort' => 'Integrationsprüfung',
            'organisation' => 'THW',
            'fuehrungsstellenname' => 'Führungsstelle DV-Operationen',
            'einsatzleitung' => 'Leitung Integration',
            'beschreibung' =>
                'LOOSE-Abschlussnachweis mit importierten Formaldaten.',
            'estab_permission_mode' => ESTAB_PERMISSION_MODE_LOOSE,
        ],
        'dv-close-permission-mode-test',
        true,
        (int) $strictClosureStatus['revision'],
        true
    );
    $looseClosureIncidentId = (int) $looseClosureCreated['einsatz_id'];
    $retainedShiftInsert = $connection->prepare(
        'INSERT INTO `nv_dienstschichten`'
            . ' (`einsatz_id`, `nummer`, `bezeichnung`, `status`,'
            . ' `erstellt_von`)'
            . " VALUES (?, 1, ?, 'GEPLANT', ?)"
    );
    if (!$retainedShiftInsert) {
        throw new RuntimeException('Could not prepare retained LOOSE shift');
    }
    try {
        $retainedShiftLabel = 'Importierte historische Dienstschicht';
        $retainedShiftInsert->bind_param(
            'iss',
            $looseClosureIncidentId,
            $retainedShiftLabel,
            $actor
        );
        $retainedShiftInsert->execute();
        $looseClosureShiftId = (int) $connection->insert_id;
    } finally {
        $retainedShiftInsert->close();
    }
    $retainedAssignmentInsert = $connection->prepare(
        'INSERT INTO `nv_dienstbesetzungen`'
            . ' (`dienstschicht_id`, `benutzer_kuerzel`, `funktion`, `rolle`,'
            . ' `status`, `zugewiesen_von`)'
            . " VALUES (?, ?, 'S2', 'Stab', 'ZUGEWIESEN', ?)"
    );
    if (!$retainedAssignmentInsert) {
        throw new RuntimeException('Could not prepare retained LOOSE assignment');
    }
    try {
        $retainedAssignmentCode = $codes['s2'];
        $retainedAssignmentInsert->bind_param(
            'iss',
            $looseClosureShiftId,
            $retainedAssignmentCode,
            $actor
        );
        $retainedAssignmentInsert->execute();
    } finally {
        $retainedAssignmentInsert->close();
    }
    $looseClosureIncident = estab_incident_status($connection);
    estab_permission_context_set_from_incident($looseClosureIncident);
    $looseClosurePreflight = estab_incident_close_preflight(
        $connection,
        $looseClosureIncidentId
    );
    $assert(
        $looseClosurePreflight['closable'] === true
            && $looseClosurePreflight['offene_schichten'] > 0
            && $looseClosurePreflight['offene_besetzungen'] > 0,
        'LOOSE treated retained formal duty records as closure blockers'
    );
    $looseClosureStatus = estab_incident_status($connection);
    $strictClosureIncident = estab_incident_activate(
        $connection,
        $incidentId,
        (int) $looseClosureStatus['revision'],
        'dv-close-permission-mode-test'
    );
    estab_permission_context_set_from_incident($strictClosureIncident);
    $assert(
        estab_incident_close_preflight(
            $connection,
            $incidentId,
            null,
            $secondShiftId
        )['closable'] === true,
        'STRICT closingShiftId exception did not permit the governed final close'
    );
    estab_dv_close_shift(
        $connection,
        $incidentId,
        $secondShiftId,
        $actor
    );
    $assert(
        !estab_auth_duty_assignment_matches_session(
            $connection,
            $secondAssignments['s2'],
            $codes['s2_successor'],
            'S2',
            'Stab'
        ),
        'STRICT session validation retained an assignment after shift closure'
    );
    $restartShift = estab_dv_create_shift(
        $connection,
        $incidentId,
        'Unzulässiger Schichtneustart',
        null,
        $actor
    );
    $restartShiftId = (int) $restartShift['dienstschicht_id'];
    $expect(
        EstabDvConflictException::class,
        static fn (): null => (
            estab_dv_activate_initial_shift(
                $connection,
                $incidentId,
                $restartShiftId,
                $actor
            ) ?? null
        ),
        'initial activation reopened duty operation after historic activation'
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $restartShiftId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_dienstschichten`'
                . " SET `status` = 'AKTIV', `aktiviert_am` = NOW(6)"
                . ' WHERE `dienstschicht_id` = ?'
            );
            try {
                $statement->bind_param('i', $restartShiftId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'database allowed initial activation after historic duty operation'
    );
    estab_dv_close_shift(
        $connection,
        $incidentId,
        $restartShiftId,
        $actor
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $awIdentity
        ),
        'STRICT admitted a fixed account after all duty shifts were closed'
    );
    $blockers = estab_dv_incident_closure_blockers(
        $connection,
        $incidentId
    );
    $assert(
        $blockers['offene_schichten'] === 0
            && $blockers['offene_besetzungen'] === 0
            && $blockers['offene_melderauftraege'] === 0
            && $blockers['offene_fernmeldeplanentwuerfe'] === 0
            && $blockers['offene_uebergabeanforderungen'] === 0
            && $blockers['betriebsereigniskette_gueltig'] === true,
        'closed command-post operation retained organisational blockers'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_dienstschichten`'
                . " WHERE `einsatz_id` = ? AND `status` = 'UEBERGEBEN'",
            'i',
            $incidentId
        ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_dienstschichten`'
                    . " WHERE `einsatz_id` = ? AND `status` = 'GESCHLOSSEN'",
                'i',
                $incidentId
            ) === 2,
        'shift handover/closure history was not retained'
    );

    printf(
        "DV operations integration: OK (%d assertions, %d events)\n",
        $assertions,
        (int) $blockers['betriebsereignisse']
    );
} finally {
    estab_auth_close($connection);
    if ($sessionStarted && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
}
