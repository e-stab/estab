<?php

if (!function_exists('estab_env')) {
    require_once __DIR__ . '/../../app/bootstrap.php';
}
require_once __DIR__ . '/../../app/attachment.php';
require_once __DIR__ . '/../../app/file_access.php';

function attachment_db_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function attachment_db_password(): string
{
    $password = getenv('ESTAB_TEST_DB_PASSWORD');
    if ($password === false) {
        $password = getenv('ESTAB_DB_PASSWORD');
    }
    if ($password !== false && $password !== '') {
        return $password;
    }

    $secretFile = getenv('ESTAB_DB_PASSWORD_FILE');
    if ($secretFile !== false && is_readable($secretFile)) {
        return trim((string) file_get_contents($secretFile));
    }
    return '';
}

function attachment_db_config(): array
{
    $host = getenv('ESTAB_TEST_DB_HOST') ?: (getenv('ESTAB_DB_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('ESTAB_TEST_DB_PORT') ?: (getenv('ESTAB_DB_PORT') ?: 3306));

    return [
        'server' => $host . ':' . $port,
        'user' => getenv('ESTAB_TEST_DB_USER') ?: (getenv('ESTAB_DB_USER') ?: 'estab'),
        'password' => attachment_db_password(),
        'datenbank' => getenv('ESTAB_TEST_DB_NAME') ?: (getenv('ESTAB_DB_NAME') ?: 'estab'),
    ];
}

function attachment_db_identity(): array
{
    $name = getenv('ESTAB_TEST_ATTACHMENT_USER');
    $code = getenv('ESTAB_TEST_ATTACHMENT_CODE');
    $role = getenv('ESTAB_TEST_ATTACHMENT_ROLE');
    $assignmentId = getenv('ESTAB_TEST_ATTACHMENT_ASSIGNMENT_ID');
    if (
        !is_string($name)
        || $name === ''
        || !is_string($code)
        || preg_match('/\A[a-z0-9]{1,6}\z/D', $code) !== 1
        || !is_string($role)
        || $role === ''
        || !is_string($assignmentId)
        || preg_match('/\A[1-9][0-9]*\z/D', $assignmentId) !== 1
    ) {
        throw new RuntimeException(
            'Attachment integration duty identity is incomplete'
        );
    }

    return [
        'benutzer' => $name,
        'kuerzel' => $code,
        'funktion' => 'A/W',
        'rolle' => $role,
        'duty_assignment_id' => (int) $assignmentId,
    ];
}

function attachment_db_drop_fixture(array $config, string $table): void
{
    try {
        $connection = estab_attachment_connection($config);
        $connection->query('DROP TABLE IF EXISTS ' . estab_attachment_table($table));
        estab_attachment_close($connection);
    } catch (Throwable) {
        // A shutdown cleanup must not hide the original test failure.
    }
}

/**
 * Close the isolated fixture shift and restore the incident that was active
 * before this test. A fresh connection makes this usable from both finally
 * and the shutdown fallback after a failed/broken test connection.
 */
function attachment_db_restore_incident(
    array $config,
    ?int $previousIncidentId,
    int $fixtureIncidentId,
    int $fixtureShiftId
): void {
    if ($fixtureIncidentId < 1) {
        return;
    }

    $connection = null;
    $cleanupFailure = null;
    try {
        $connection = estab_attachment_connection($config);
        $status = estab_incident_status($connection);
        $activeIncidentId = $status['active_einsatz_id'] === null
            ? null
            : (int) $status['active_einsatz_id'];

        if ($activeIncidentId === $fixtureIncidentId && $fixtureShiftId > 0) {
            try {
                $shift = $connection->prepare(
                    'SELECT `status` FROM `nv_dienstschichten`'
                    . ' WHERE `dienstschicht_id` = ? AND `einsatz_id` = ?'
                    . ' LIMIT 1'
                );
                attachment_db_assert(
                    $shift instanceof mysqli_stmt,
                    'Could not prepare attachment shift cleanup'
                );
                try {
                    $shift->bind_param(
                        'ii',
                        $fixtureShiftId,
                        $fixtureIncidentId
                    );
                    attachment_db_assert(
                        $shift->execute(),
                        'Could not inspect attachment shift cleanup'
                    );
                    $shiftRow = $shift->get_result()->fetch_assoc();
                } finally {
                    $shift->close();
                }
                if (
                    is_array($shiftRow)
                    && in_array(
                        (string) ($shiftRow['status'] ?? ''),
                        ['GEPLANT', 'AKTIV'],
                        true
                    )
                ) {
                    estab_dv_close_shift(
                        $connection,
                        $fixtureIncidentId,
                        $fixtureShiftId,
                        'attachment-integration-cleanup'
                    );
                }
            } catch (Throwable $exception) {
                // Restoring the shared singleton remains mandatory even when
                // closing a partially built fixture shift fails.
                $cleanupFailure = $exception;
            }
            $status = estab_incident_status($connection);
            $activeIncidentId = $status['active_einsatz_id'] === null
                ? null
                : (int) $status['active_einsatz_id'];
        }

        try {
            if ($activeIncidentId === $fixtureIncidentId) {
                if ($previousIncidentId !== null) {
                    estab_incident_activate(
                        $connection,
                        $previousIncidentId,
                        (int) $status['revision'],
                        'attachment-integration-cleanup'
                    );
                } else {
                    estab_incident_deactivate(
                        $connection,
                        $fixtureIncidentId,
                        (int) $status['revision'],
                        'attachment-integration-cleanup'
                    );
                }
            } elseif (
                $activeIncidentId === null
                && $previousIncidentId !== null
            ) {
                estab_incident_activate(
                    $connection,
                    $previousIncidentId,
                    (int) $status['revision'],
                    'attachment-integration-cleanup'
                );
            } elseif (
                $activeIncidentId !== $previousIncidentId
                && $cleanupFailure === null
            ) {
                throw new RuntimeException(
                    'Attachment cleanup found an unexpected active incident'
                );
            }
        } catch (Throwable $exception) {
            if ($cleanupFailure === null) {
                $cleanupFailure = $exception;
            }
        }
    } catch (Throwable $exception) {
        if ($cleanupFailure === null) {
            $cleanupFailure = $exception;
        }
    } finally {
        if ($connection instanceof mysqli) {
            estab_attachment_close($connection);
        }
    }

    if ($cleanupFailure instanceof Throwable) {
        throw $cleanupFailure;
    }
}

function attachment_db_row(mysqli $connection, string $table, string $filename): ?array
{
    $sql = 'SELECT `filename`, `fileext`, `org_filename`, `comment`, `md5hash`,'
        . ' `integrity_required`, `ingest_sha256`, `ingest_size`,'
        . ' `integrity_captured_at`,'
        . ' `date`, `kuerzel`, `status`, `id`'
        . ' FROM ' . estab_attachment_table($table) . ' WHERE `filename` = ? LIMIT 1';
    $statement = $connection->prepare($sql);
    attachment_db_assert($statement instanceof mysqli_stmt, 'Could not prepare fixture lookup');
    try {
        $statement->bind_param('s', $filename);
        attachment_db_assert($statement->execute(), 'Could not execute fixture lookup');
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
}

function attachment_db_count(mysqli $connection, string $table): int
{
    $result = $connection->query(
        'SELECT COUNT(*) FROM ' . estab_attachment_table($table)
    );
    attachment_db_assert($result instanceof mysqli_result, 'Could not count fixture rows');
    $row = $result->fetch_row();
    $result->free();
    return (int) ($row[0] ?? -1);
}

function attachment_db_status_counter(mysqli $connection, string $name): int
{
    if ($name !== 'Innodb_deadlocks') {
        throw new InvalidArgumentException('Unsupported MariaDB status counter');
    }
    $result = $connection->query("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'");
    attachment_db_assert($result instanceof mysqli_result, 'Could not read MariaDB deadlock counter');
    try {
        $row = $result->fetch_assoc();
        attachment_db_assert(is_array($row), 'MariaDB deadlock counter is missing');
        $value = (string) ($row['Value'] ?? '');
        attachment_db_assert(
            preg_match('/\A[0-9]+\z/D', $value) === 1,
            'MariaDB deadlock counter is invalid'
        );
        return (int) $value;
    } finally {
        $result->free();
    }
}

/**
 * Make mysqli receive one row before MariaDB reports a lock timeout.
 *
 * mysqlnd therefore completes execute(), while get_result() fails as it tries
 * to buffer the remaining row. This proves that the production result helper
 * preserves the deferred 1205 code instead of dereferencing false.
 */
function attachment_db_prove_deferred_result_timeout(
    mysqli $connectionA,
    mysqli $connectionB,
    string $table
): void {
    $quotedTable = estab_attachment_table($table);
    $firstName = 'DP0001';
    $secondName = 'DP0002';
    $insert = null;
    $blockedStatement = null;
    $originalTimeout = 50;

    $timeoutResult = $connectionB->query('SELECT @@SESSION.innodb_lock_wait_timeout');
    attachment_db_assert($timeoutResult instanceof mysqli_result, 'Could not read lock wait timeout');
    try {
        $timeoutRow = $timeoutResult->fetch_row();
        attachment_db_assert(is_array($timeoutRow), 'Lock wait timeout is missing');
        $originalTimeout = (int) ($timeoutRow[0] ?? 50);
    } finally {
        $timeoutResult->free();
    }

    try {
        $insert = $connectionA->prepare(
            'INSERT INTO ' . $quotedTable . " (`filename`, `status`, `id`) VALUES (?, 4, '')"
        );
        attachment_db_assert($insert instanceof mysqli_stmt, 'Could not prepare deferred-result fixture');
        $probeName = $firstName;
        $insert->bind_param('s', $probeName);
        attachment_db_assert($insert->execute(), 'Could not insert first deferred-result row');
        $firstId = (int) $connectionA->insert_id;
        $probeName = $secondName;
        attachment_db_assert($insert->execute(), 'Could not insert second deferred-result row');
        $secondId = (int) $connectionA->insert_id;
        attachment_db_assert(
            $firstId > 0 && $secondId > $firstId,
            'Deferred-result fixture has invalid sequence identifiers'
        );
        $insert->close();
        $insert = null;

        attachment_db_assert($connectionA->begin_transaction(), 'Could not start blocking transaction');
        $lockResult = $connectionA->query(
            'SELECT `filename` FROM ' . $quotedTable
            . ' WHERE `lfd-nr` = ' . $secondId . ' FOR UPDATE'
        );
        attachment_db_assert($lockResult instanceof mysqli_result, 'Could not lock deferred-result row');
        $lockResult->free();

        attachment_db_assert(
            $connectionB->query('SET SESSION innodb_lock_wait_timeout = 1'),
            'Could not shorten lock wait timeout'
        );
        attachment_db_assert($connectionB->begin_transaction(), 'Could not start deferred-result transaction');

        $blockedStatement = $connectionB->prepare(
            'SELECT `filename` FROM ' . $quotedTable
            . ' WHERE `lfd-nr` >= ? ORDER BY `lfd-nr` FOR UPDATE'
        );
        attachment_db_assert(
            $blockedStatement instanceof mysqli_stmt,
            'Could not prepare deferred-result lookup'
        );
        $blockedStatement->bind_param('i', $firstId);
        $started = microtime(true);
        attachment_db_assert(
            $blockedStatement->execute(),
            'Lock timeout was not deferred until result buffering'
        );

        $failure = null;
        try {
            $unexpectedResult = estab_attachment_statement_result(
                $blockedStatement,
                $connectionB,
                'Deferred lock timeout probe'
            );
            $unexpectedResult->free();
        } catch (EstabAttachmentDatabaseException $exception) {
            $failure = $exception;
        }
        $elapsed = microtime(true) - $started;
        attachment_db_assert(
            $failure instanceof EstabAttachmentDatabaseException
                && $failure->getCode() === 1205,
            'Deferred get_result() failure did not preserve MariaDB error 1205'
        );
        attachment_db_assert($elapsed < 10.0, 'Deferred lock timeout exceeded its test bound');
    } finally {
        if ($blockedStatement instanceof mysqli_stmt) {
            $blockedStatement->close();
        }
        if ($insert instanceof mysqli_stmt) {
            $insert->close();
        }
        $connectionB->rollback();
        $connectionA->rollback();
        $connectionB->query(
            'SET SESSION innodb_lock_wait_timeout = ' . max(1, $originalTimeout)
        );

        $delete = $connectionA->prepare(
            'DELETE FROM ' . $quotedTable . ' WHERE `filename` IN (?, ?)'
        );
        if ($delete instanceof mysqli_stmt) {
            $delete->bind_param('ss', $firstName, $secondName);
            $delete->execute();
            $delete->close();
        }
    }
}

function attachment_db_worker(array $arguments): never
{
    [$table, $prefix, $sessionId, $barrier] = $arguments;
    set_time_limit(45);
    $connection = estab_attachment_connection(attachment_db_config());
    $readyFile = $barrier . '.' . $sessionId . '.ready';
    file_put_contents($readyFile, 'ready', LOCK_EX);

    $deadline = microtime(true) + 15.0;
    while (!is_file($barrier)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Reservation worker barrier timed out');
        }
        usleep(10_000);
    }

    try {
        $retries = [];
        $filename = estab_attachment_reserve(
            $connection,
            $table,
            $prefix,
            $sessionId,
            attachment_db_identity(),
            4,
            8,
            static function (int $attempt, int $code) use (&$retries): void {
                $retries[] = ['attempt' => $attempt, 'code' => $code];
            }
        );
        echo json_encode(
            ['filename' => $filename, 'retries' => $retries],
            JSON_THROW_ON_ERROR
        ), "\n";
    } finally {
        estab_attachment_close($connection);
    }
    exit(0);
}

function attachment_db_start_worker(
    string $table,
    string $prefix,
    string $sessionId,
    string $barrier
): array {
    $command = [
        PHP_BINARY,
        '-d',
        'auto_prepend_file=',
        __FILE__,
        '--reserve-worker',
        $table,
        $prefix,
        $sessionId,
        $barrier,
    ];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    attachment_db_assert(is_resource($process), 'Could not start reservation worker');
    return [$process, $pipes, $sessionId];
}

/**
 * @return array{filename: string, retries: list<array{attempt: int, code: int}>}
 */
function attachment_db_finish_worker(array $worker): array
{
    [$process, $pipes, $sessionId] = $worker;
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $exitCode = proc_close($process);
    attachment_db_assert(
        $exitCode === 0,
        'Reservation worker ' . $sessionId . ' failed: ' . trim((string) $stderr)
    );
    try {
        $result = json_decode(trim((string) $stdout), true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(
            'Reservation worker ' . $sessionId . ' returned invalid JSON',
            0,
            $exception
        );
    }
    attachment_db_assert(is_array($result), 'Reservation worker result is not an object');
    $filename = estab_attachment_validate_reservation_name(
        (string) ($result['filename'] ?? ''),
        'IT'
    );
    $retries = $result['retries'] ?? null;
    attachment_db_assert(is_array($retries) && array_is_list($retries), 'Worker retry trace is invalid');
    $validatedRetries = [];
    foreach ($retries as $retry) {
        attachment_db_assert(is_array($retry), 'Worker retry trace entry is invalid');
        $attempt = $retry['attempt'] ?? null;
        $code = $retry['code'] ?? null;
        attachment_db_assert(
            is_int($attempt) && $attempt >= 1 && $attempt < 8,
            'Worker retry attempt is invalid'
        );
        attachment_db_assert(
            is_int($code) && estab_attachment_database_error_is_retryable($code),
            'Worker retry code is invalid'
        );
        $validatedRetries[] = ['attempt' => $attempt, 'code' => $code];
    }
    return ['filename' => $filename, 'retries' => $validatedRetries];
}

if (($argv[1] ?? '') === '--reserve-worker') {
    try {
        attachment_db_worker(array_slice($argv, 2, 4));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

set_time_limit(60);
$config = attachment_db_config();
$token = strtolower(bin2hex(random_bytes(5)));
$table = 'estab_test_anhang_' . getmypid() . '_' . $token;
$prefix = 'IT';
$sessionA = 'it_a_' . $token;
$sessionB = 'it_b_' . $token;
$sessionC = 'it_c_' . $token;
$barrier = sys_get_temp_dir() . '/estab-attachment-' . $token;
$fixtureUser = '';
$fixtureCode = '';
$fixtureRole = '';
$previousIncidentId = null;
$fixtureIncidentId = 0;
$fixtureShiftId = 0;
$readyFiles = [
    $barrier . '.' . $sessionA . '.ready',
    $barrier . '.' . $sessionB . '.ready',
];

attachment_db_assert(strlen($table) <= 64, 'Fixture table identifier is too long');
register_shutdown_function(
    static function () use (
        $config,
        $table,
        $barrier,
        $readyFiles,
        &$previousIncidentId,
        &$fixtureIncidentId,
        &$fixtureShiftId
    ): void {
        try {
            attachment_db_restore_incident(
                $config,
                $previousIncidentId,
                $fixtureIncidentId,
                $fixtureShiftId
            );
        } catch (Throwable) {
            // CI destroys the ephemeral volume; shutdown cleanup is best effort.
        }
        attachment_db_drop_fixture($config, $table);
        @unlink($barrier);
        foreach ($readyFiles as $readyFile) {
            @unlink($readyFile);
        }
    }
);

$connectionA = null;
$connectionB = null;
$workers = [];
try {
    attachment_db_drop_fixture($config, $table);
    $connectionA = estab_attachment_connection($config);
    $connectionB = estab_attachment_connection($config);
    attachment_db_assert(
        $connectionA->thread_id !== $connectionB->thread_id,
        'Ownership checks require two independent MariaDB connections'
    );
    $initialStatus = estab_incident_status($connectionA);
    $previousIncidentId = $initialStatus['active_einsatz_id'] === null
        ? null
        : (int) $initialStatus['active_einsatz_id'];
    $fixtureIncident = estab_incident_create(
        $connectionA,
        [
            'kennung' => 'CI-ATT-' . strtoupper($token),
            'name' => 'Attachment reservation ' . $token,
            'beginn' => date('Y-m-d\TH:i'),
            'beschreibung' =>
                'Ephemerer Einsatz für isolierte Anhangreservierungen.',
            'metadaten' => json_encode(
                [
                    'test' => 'attachment_reservation',
                    'token' => $token,
                ],
                JSON_THROW_ON_ERROR
            ),
        ],
        'attachment-integration',
        false
    );
    $fixtureIncidentId = (int) (
        $fixtureIncident['einsatz_id'] ?? 0
    );
    attachment_db_assert(
        $fixtureIncidentId > 0
            && $fixtureIncidentId !== ($previousIncidentId ?? 0),
        'Could not create an isolated attachment incident'
    );
    $activationStatus = estab_incident_status($connectionA);
    $activatedFixture = estab_incident_activate(
        $connectionA,
        $fixtureIncidentId,
        (int) $activationStatus['revision'],
        'attachment-integration'
    );
    attachment_db_assert(
        (int) ($activatedFixture['active_einsatz_id'] ?? 0)
            === $fixtureIncidentId,
        'Could not activate the isolated attachment incident'
    );
    attachment_db_assert(
        estab_dv_active_shift_summary(
            $connectionA,
            $fixtureIncidentId
        ) === null,
        'Attachment integration requires no pre-existing active duty shift'
    );

    $fixtureShift = estab_dv_create_shift(
        $connectionA,
        $fixtureIncidentId,
        'Parallele Anhangreservierung ' . $token,
        null,
        'attachment-integration'
    );
    $fixtureShiftId = (int) ($fixtureShift['dienstschicht_id'] ?? 0);
    attachment_db_assert(
        $fixtureShiftId > 0,
        'Could not create attachment duty shift'
    );
    $fixtureAssignmentId = 0;
    $functionRoles = estab_dv_function_roles($connectionA);
    foreach (
        array_values(ESTAB_DV_REQUIRED_HATS)
        as $functionIndex => $function
    ) {
        $assignment = estab_dv_role_for_function($functionRoles, $function);
        $fixtureFunctionCode = 'a'
            . base_convert((string) ($functionIndex + 1), 10, 36)
            . substr($token, 0, 4);
        $fixtureFunctionUser = 'Attachment '
            . $assignment['funktion'] . ' ' . $token;
        $fixturePassword = password_hash(
            'Attachment integration ' . $fixtureFunctionCode,
            PASSWORD_DEFAULT
        );
        $fixtureSid = 'attachment-' . $fixtureFunctionCode . '-' . $token;
        $fixtureAccount = $connectionA->prepare(
            'INSERT INTO `nv_benutzer`'
            . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`,'
            . ' `aktiv`, `estab_gesperrt`, `password`)'
            . ' VALUES (?, ?, ?, ?, ?, 1, 0, ?)'
        );
        attachment_db_assert(
            $fixtureAccount instanceof mysqli_stmt,
            'Could not prepare attachment duty account'
        );
        try {
            $fixtureAccount->bind_param(
                'ssssss',
                $fixtureFunctionUser,
                $fixtureFunctionCode,
                $assignment['funktion'],
                $assignment['rolle'],
                $fixtureSid,
                $fixturePassword
            );
            attachment_db_assert(
                $fixtureAccount->execute(),
                "Could not create attachment {$function} duty account"
            );
        } finally {
            $fixtureAccount->close();
        }

        $assignment = estab_dv_assign_hat(
            $connectionA,
            $fixtureIncidentId,
            $fixtureShiftId,
            $fixtureFunctionCode,
            $function,
            'attachment-integration'
        );
        $assignmentId = (int) (
            $assignment['dienstbesetzung_id'] ?? 0
        );
        attachment_db_assert(
            $assignmentId > 0,
            "Could not assign attachment duty hat {$function}"
        );
        estab_dv_accept_hat(
            $connectionA,
            $fixtureIncidentId,
            $assignmentId,
            $fixtureFunctionCode
        );
        if ($function === 'A/W') {
            $fixtureAssignmentId = $assignmentId;
            $fixtureUser = $fixtureFunctionUser;
            $fixtureCode = $fixtureFunctionCode;
            $fixtureRole = (string) $assignment['rolle'];
        }
    }
    attachment_db_assert(
        $fixtureAssignmentId > 0
            && $fixtureUser !== ''
            && $fixtureCode !== ''
            && $fixtureRole !== '',
        'Attachment fixture did not create its selected A/W assignment'
    );
    estab_dv_activate_initial_shift(
        $connectionA,
        $fixtureIncidentId,
        $fixtureShiftId,
        'attachment-integration'
    );
    attachment_db_assert(
        putenv('ESTAB_TEST_ATTACHMENT_USER=' . $fixtureUser)
            && putenv('ESTAB_TEST_ATTACHMENT_CODE=' . $fixtureCode)
            && putenv('ESTAB_TEST_ATTACHMENT_ROLE=' . $fixtureRole)
            && putenv(
                'ESTAB_TEST_ATTACHMENT_ASSIGNMENT_ID='
                . $fixtureAssignmentId
            ),
        'Could not publish attachment duty identity to workers'
    );

    $fixtureSql = 'CREATE TABLE ' . estab_attachment_table($table) . ' ('
        . ' `lfd-nr` BIGINT NOT NULL AUTO_INCREMENT,'
        . ' `einsatz_id` BIGINT UNSIGNED NULL DEFAULT NULL,'
        . " `filename` VARCHAR(255) NOT NULL DEFAULT '',"
        . " `fileext` VARCHAR(16) NOT NULL DEFAULT '',"
        . " `org_filename` VARCHAR(255) NOT NULL DEFAULT '',"
        . " `comment` VARCHAR(255) NOT NULL DEFAULT '',"
        . " `md5hash` VARCHAR(32) NOT NULL DEFAULT '',"
        . ' `integrity_required` TINYINT UNSIGNED NOT NULL DEFAULT 1,'
        . ' `ingest_sha256` CHAR(64) CHARACTER SET ascii'
        . ' COLLATE ascii_bin NULL DEFAULT NULL,'
        . ' `ingest_size` BIGINT UNSIGNED NULL DEFAULT NULL,'
        . ' `integrity_captured_at` DATETIME(6) NULL DEFAULT NULL,'
        . ' `date` DATETIME NULL DEFAULT NULL,'
        . ' `kuerzel` VARCHAR(6) NULL DEFAULT NULL,'
        . ' `status` TINYINT NOT NULL DEFAULT 1,'
        . " `id` VARCHAR(128) NOT NULL DEFAULT '',"
        . ' PRIMARY KEY (`lfd-nr`),'
        . ' UNIQUE KEY `uq_filename` (`filename`),'
        . ' KEY `idx_filename_status` (`filename`, `status`),'
        . ' KEY `idx_id` (`id`)'
        . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    attachment_db_assert($connectionA->query($fixtureSql), 'Could not create attachment fixture');

    attachment_db_prove_deferred_result_timeout($connectionA, $connectionB, $table);
    attachment_db_assert(
        attachment_db_count($connectionA, $table) === 0,
        'Deferred-result fixture cleanup left rows behind'
    );

    @unlink($barrier);
    foreach ($readyFiles as $readyFile) {
        @unlink($readyFile);
    }

    // Both writers first lock the singleton active-incident row. That
    // authoritative lock order must serialize filename allocation without
    // relying on a deadlock/retry to produce distinct reservations.
    $deadlocksBefore = attachment_db_status_counter($connectionA, 'Innodb_deadlocks');
    $workers = [];
    $workers[] = attachment_db_start_worker($table, $prefix, $sessionA, $barrier);
    $workers[] = attachment_db_start_worker($table, $prefix, $sessionB, $barrier);
    $readyDeadline = microtime(true) + 10.0;
    while (!is_file($readyFiles[0]) || !is_file($readyFiles[1])) {
        if (microtime(true) >= $readyDeadline) {
            throw new RuntimeException('Concurrent reservation workers did not become ready');
        }
        usleep(10_000);
    }
    attachment_db_assert(
        file_put_contents($barrier, 'go', LOCK_EX) !== false,
        'Could not release reservation worker barrier'
    );

    $workerA = attachment_db_finish_worker($workers[0]);
    $workerB = attachment_db_finish_worker($workers[1]);
    $workers = [];
    $filenameA = $workerA['filename'];
    $filenameB = $workerB['filename'];
    $workerRetryCodes = array_merge(
        array_column($workerA['retries'], 'code'),
        array_column($workerB['retries'], 'code')
    );
    $deadlocksAfter = attachment_db_status_counter($connectionA, 'Innodb_deadlocks');
    attachment_db_assert(
        $deadlocksAfter === $deadlocksBefore && $workerRetryCodes === [],
        'Active-incident locking did not serialize concurrent reservations'
    );
    attachment_db_assert($filenameA !== $filenameB, 'Concurrent reservations collided');
    attachment_db_assert(
        [$filenameA, $filenameB] === ['IT0001', 'IT0002']
            || [$filenameA, $filenameB] === ['IT0002', 'IT0001'],
        'Concurrent sequence did not reserve IT0001 and IT0002'
    );
    attachment_db_assert(attachment_db_count($connectionA, $table) === 2, 'Reservation row count differs');
    attachment_db_assert(
        (attachment_db_row($connectionA, $table, $filenameA)['id'] ?? '') === $sessionA,
        'Worker A does not own its reservation'
    );
    attachment_db_assert(
        (attachment_db_row($connectionB, $table, $filenameB)['id'] ?? '') === $sessionB,
        'Worker B does not own its reservation'
    );

    attachment_db_assert(
        !estab_attachment_claim(
            $connectionB,
            $table,
            $filenameA,
            $sessionB,
            attachment_db_identity()
        ),
        'Foreign session claimed worker A reservation'
    );
    attachment_db_assert(
        estab_attachment_claim(
            $connectionA,
            $table,
            $filenameA,
            $sessionA,
            attachment_db_identity()
        ),
        'Owner could not claim its reservation'
    );
    attachment_db_assert(
        !estab_attachment_claim(
            $connectionA,
            $table,
            $filenameA,
            $sessionA,
            attachment_db_identity()
        ),
        'Claim was not state-idempotent'
    );
    estab_attachment_release($connectionB, $table, $sessionB, $filenameA);
    $claimed = attachment_db_row($connectionA, $table, $filenameA);
    attachment_db_assert(
        (int) ($claimed['status'] ?? -1) === 2 && ($claimed['id'] ?? '') === $sessionA,
        'Foreign release changed an owned claim'
    );

    $metadata = estab_attachment_validate_metadata([
        'filename' => $filenameA . '.pdf',
        'org_filename' => 'integration-report.pdf',
        'comment' => 'Atomic attachment integration fixture',
        'time' => '2026-07-23 12:34:56',
        'md5hash' => md5('attachment-' . $token),
        'sha256' => hash('sha256', 'attachment-' . $token),
        'size' => strlen('attachment-' . $token),
    ], $filenameA, (string) attachment_db_identity()['kuerzel']);
    attachment_db_assert(
        !estab_attachment_finalize(
            $connectionB,
            $table,
            $sessionB,
            $metadata,
            attachment_db_identity()
        ),
        'Foreign session finalised an owned claim'
    );
    attachment_db_assert(
        estab_attachment_finalize(
            $connectionA,
            $table,
            $sessionA,
            $metadata,
            attachment_db_identity()
        ),
        'Owner could not finalise its claim'
    );
    attachment_db_assert(
        !estab_attachment_finalize(
            $connectionA,
            $table,
            $sessionA,
            $metadata,
            attachment_db_identity()
        ),
        'Repeated finalisation unexpectedly changed the completed row'
    );
    $finalised = attachment_db_row($connectionB, $table, $filenameA);
    attachment_db_assert(
        (int) ($finalised['status'] ?? -1) === 1
            && ($finalised['id'] ?? null) === ''
            && ($finalised['md5hash'] ?? null) === $metadata['md5hash']
            && ($finalised['ingest_sha256'] ?? null) === $metadata['sha256']
            && (int) ($finalised['ingest_size'] ?? -1) === $metadata['size']
            && ($finalised['integrity_captured_at'] ?? null) !== null,
        'Finalised metadata or state differs'
    );
    attachment_db_assert(
        estab_attachment_find($connectionB, $table, $filenameA) !== null,
        'Finalised attachment cannot be found'
    );
    attachment_db_assert(
        estab_attachment_find(
            $connectionB,
            $table,
            strtolower($filenameA)
        ) === null,
        'Case-insensitive database collation authorized a different pathname'
    );

    // A direct download/preview authorization must keep the singleton status
    // locked until the already-authorized pathname has become a stable open
    // file handle. A competing incident switch therefore cannot slip into the
    // gap between the database decision and fopen().
    $authorizationRoot = sys_get_temp_dir()
        . '/estab-file-authorization-' . $token;
    attachment_db_assert(
        mkdir($authorizationRoot, 0700),
        'Could not create file-authorization fixture directory'
    );
    $authorizationName = $filenameA . '.pdf';
    $authorizationBytes = "%PDF-1.7\nincident-lock-" . $token . "\n%%EOF\n";
    attachment_db_assert(
        file_put_contents(
            $authorizationRoot . '/' . $authorizationName,
            $authorizationBytes,
            LOCK_EX
        ) === strlen($authorizationBytes),
        'Could not write file-authorization fixture'
    );
    $authorizedStream = null;
    $timeoutResult = $connectionB->query(
        'SELECT @@SESSION.innodb_lock_wait_timeout'
    );
    attachment_db_assert(
        $timeoutResult instanceof mysqli_result,
        'Could not read authorization lock timeout'
    );
    try {
        $timeoutRow = $timeoutResult->fetch_row();
        $originalAuthorizationTimeout = max(1, (int) ($timeoutRow[0] ?? 50));
    } finally {
        $timeoutResult->free();
    }
    try {
        attachment_db_assert(
            $connectionA->begin_transaction(),
            'Could not start file-authorization transaction'
        );
        attachment_db_assert(
            is_array(
                estab_attachment_find(
                    $connectionA,
                    $table,
                    $filenameA,
                    true
                )
            ),
            'Locked file authorization did not find its active attachment'
        );
        $authorizedStream = estab_file_open(
            $authorizationRoot,
            'attachment',
            $authorizationName
        );
        attachment_db_assert(
            is_resource($authorizedStream),
            'Locked file authorization returned no open handle'
        );

        attachment_db_assert(
            $connectionB->query(
                'SET SESSION innodb_lock_wait_timeout = 1'
            ),
            'Could not shorten authorization lock timeout'
        );
        attachment_db_assert(
            $connectionB->begin_transaction(),
            'Could not start competing incident transaction'
        );
        $switchFailure = null;
        $switchFailureCode = 0;
        try {
            estab_incident_require_active($connectionB, true);
        } catch (Throwable $exception) {
            $switchFailure = $exception;
            $switchFailureCode = $connectionB->errno ?: $exception->getCode();
        }
        $connectionB->rollback();
        attachment_db_assert(
            $switchFailure instanceof Throwable
                && $switchFailureCode === 1205,
            'Incident status was not locked through the authorized file open'
        );

        attachment_db_assert(
            $connectionA->commit(),
            'Could not commit file-authorization transaction'
        );
        attachment_db_assert(
            stream_get_contents($authorizedStream) === $authorizationBytes,
            'Authorized handle changed after releasing the incident lock'
        );
        fclose($authorizedStream);
        $authorizedStream = null;

        attachment_db_assert(
            $connectionB->begin_transaction(),
            'Could not retry incident status lock'
        );
        $statusAfterOpen = estab_incident_require_active($connectionB, true);
        attachment_db_assert(
            (int) ($statusAfterOpen['active_einsatz_id'] ?? 0) > 0,
            'Incident status lock did not become available after file open'
        );
        $connectionB->rollback();
    } finally {
        if (is_resource($authorizedStream)) {
            fclose($authorizedStream);
        }
        $connectionA->rollback();
        $connectionB->rollback();
        $connectionB->query(
            'SET SESSION innodb_lock_wait_timeout = '
            . $originalAuthorizationTimeout
        );
        @unlink($authorizationRoot . '/' . $authorizationName);
        @rmdir($authorizationRoot);
    }

    $duplicate = $connectionB->prepare(
        'INSERT INTO ' . estab_attachment_table($table)
        . " (`filename`, `status`, `id`) VALUES (?, 4, '')"
    );
    attachment_db_assert($duplicate instanceof mysqli_stmt, 'Could not prepare collision probe');
    try {
        $duplicate->bind_param('s', $filenameA);
        attachment_db_assert(
            !$duplicate->execute() && $duplicate->errno === 1062,
            'Unique filename collision was not rejected'
        );
    } finally {
        $duplicate->close();
    }

    estab_attachment_release($connectionA, $table, $sessionA, $filenameB);
    attachment_db_assert(
        (int) (attachment_db_row($connectionB, $table, $filenameB)['status'] ?? -1) === 8,
        'Foreign release changed worker B reservation'
    );
    estab_attachment_release($connectionB, $table, $sessionB, $filenameB);
    attachment_db_assert(
        (int) (attachment_db_row($connectionA, $table, $filenameB)['status'] ?? -1) === 4,
        'Owner release did not make reservation reusable'
    );

    $reused = estab_attachment_reserve(
        $connectionA,
        $table,
        $prefix,
        $sessionC,
        attachment_db_identity()
    );
    attachment_db_assert($reused === $filenameB, 'Released filename was not reused');
    $reusedAgain = estab_attachment_reserve(
        $connectionB,
        $table,
        $prefix,
        $sessionC,
        attachment_db_identity()
    );
    attachment_db_assert($reusedAgain === $filenameB, 'Same-session reservation is not idempotent');
    attachment_db_assert(attachment_db_count($connectionA, $table) === 2, 'Idempotent reserve added a row');
    attachment_db_assert(
        estab_attachment_claim(
            $connectionB,
            $table,
            $filenameB,
            $sessionC,
            attachment_db_identity()
        ),
        'Reservation owner could not claim through another DB connection'
    );
    estab_attachment_release($connectionA, $table, $sessionC, $filenameB);
    attachment_db_assert(
        (int) (attachment_db_row($connectionB, $table, $filenameB)['status'] ?? -1) === 4,
        'Claim cleanup did not release the fixture'
    );

    // The browser path keeps the active-incident row locked while the upload
    // callback, final metadata update and audit insert complete.
    $uploadSession = 'it_upload_' . $token;
    $uploadName = estab_attachment_reserve(
        $connectionA,
        $table,
        $prefix,
        $uploadSession,
        attachment_db_identity()
    );
    $storedUpload = estab_attachment_store_upload(
        $connectionA,
        $table,
        'nv_protokoll',
        $uploadName,
        $uploadSession,
        (string) attachment_db_identity()['kuerzel'],
        attachment_db_identity(),
        'Anhangdaten speichern',
        static fn (): array => [
            'filename' => $uploadName . '.pdf',
            'org_filename' => 'atomic-browser-upload.pdf',
            'comment' => 'Incident-locked browser upload fixture',
            'time' => '2026-07-23 12:45:00',
            'md5hash' => md5('browser-upload-' . $token),
            'sha256' => hash('sha256', 'browser-upload-' . $token),
            'size' => strlen('browser-upload-' . $token),
        ],
        static fn (array $stored): string =>
            'integration;' . $stored['filename'] . '.' . $stored['fileext']
    );
    attachment_db_assert(
        is_array($storedUpload)
            && ($storedUpload['filename'] ?? null) === $uploadName,
        'Atomic browser upload did not return validated metadata'
    );
    attachment_db_assert(
        (int) (attachment_db_row($connectionB, $table, $uploadName)['status'] ?? -1) === 1,
        'Atomic browser upload did not finalise its reservation'
    );

    $failedSession = 'it_failed_' . $token;
    $failedName = estab_attachment_reserve(
        $connectionA,
        $table,
        $prefix,
        $failedSession,
        attachment_db_identity()
    );
    try {
        estab_attachment_store_upload(
            $connectionA,
            $table,
            'nv_protokoll',
            $failedName,
            $failedSession,
            (string) attachment_db_identity()['kuerzel'],
            attachment_db_identity(),
            'Anhangdaten speichern',
            static function (): never {
                throw new RuntimeException('deliberate upload callback failure');
            },
            static fn (array $stored): string => (string) $stored['filename']
        );
        attachment_db_assert(false, 'Failing browser upload unexpectedly succeeded');
    } catch (RuntimeException $exception) {
        attachment_db_assert(
            $exception->getMessage() === 'deliberate upload callback failure',
            'Failing browser upload did not preserve the callback error'
        );
    }
    $failedRow = attachment_db_row($connectionB, $table, $failedName);
    attachment_db_assert(
        (int) ($failedRow['status'] ?? -1) === 4
            && ($failedRow['id'] ?? null) === '',
        'Failing browser upload left a claimed reservation behind'
    );
} finally {
    foreach ($workers as $worker) {
        [$process, $pipes] = $worker;
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }
    if ($connectionB instanceof mysqli) {
        @$connectionB->rollback();
    }
    if ($connectionA instanceof mysqli) {
        @$connectionA->rollback();
    }
    attachment_db_restore_incident(
        $config,
        $previousIncidentId,
        $fixtureIncidentId,
        $fixtureShiftId
    );
    if ($connectionB instanceof mysqli) {
        estab_attachment_close($connectionB);
    }
    if ($connectionA instanceof mysqli) {
        estab_attachment_close($connectionA);
    }
    attachment_db_drop_fixture($config, $table);
    @unlink($barrier);
    foreach ($readyFiles as $readyFile) {
        @unlink($readyFile);
    }
    putenv('ESTAB_TEST_ATTACHMENT_USER');
    putenv('ESTAB_TEST_ATTACHMENT_CODE');
    putenv('ESTAB_TEST_ATTACHMENT_ROLE');
    putenv('ESTAB_TEST_ATTACHMENT_ASSIGNMENT_ID');
}

$verification = estab_attachment_connection($config);
$escapedTable = $verification->real_escape_string($table);
$result = $verification->query(
    "SELECT COUNT(*) FROM information_schema.tables"
    . " WHERE table_schema = DATABASE() AND table_name = '{$escapedTable}'"
);
attachment_db_assert($result instanceof mysqli_result, 'Could not verify fixture cleanup');
$row = $result->fetch_row();
$result->free();
estab_attachment_close($verification);
attachment_db_assert((int) ($row[0] ?? -1) === 0, 'Attachment fixture table was not removed');

echo "attachment reservation integration test: OK\n";
