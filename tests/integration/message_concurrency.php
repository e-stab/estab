<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/message_repository.php';
require_once __DIR__ . '/../../app/admin_operations.php';
require_once __DIR__ . '/../../app/generated_form.php';
require_once __DIR__ . '/../../app/workflow.php';

function message_db_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function message_db_event(
    string $eventType,
    string $user,
    string $code,
    string $function,
    string $role,
    ?int $fromStatus,
    ?int $toStatus,
    array $snapshot = []
): array {
    $actor = message_db_actor($user, $code, $function, $role);

    return [
        'event_type' => $eventType,
        'actor' => $actor,
        'from_status' => $fromStatus,
        'to_status' => $toStatus,
        'snapshot' => $snapshot,
    ];
}

/** @return array{benutzer:string,kuerzel:string,funktion:string,rolle:string} */
function message_db_actor(
    string $user,
    string $code,
    string $function,
    string $role
): array {
    $assignmentMapValue = getenv('ESTAB_TEST_MESSAGE_DUTY_ASSIGNMENTS');
    $assignmentMap = [];
    if (is_string($assignmentMapValue) && $assignmentMapValue !== '') {
        try {
            $decoded = json_decode(
                $assignmentMapValue,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Message duty assignment map is invalid',
                0,
                $exception
            );
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Message duty assignment map is not an object'
            );
        }
        $assignmentMap = $decoded;
    }
    $assignmentKey = $code . '|' . $function;
    $assignmentId = $assignmentMap[$assignmentKey] ?? null;
    if (
        $assignmentId !== null
        && (!is_int($assignmentId) || $assignmentId < 1)
    ) {
        throw new RuntimeException(
            "Message duty assignment {$assignmentKey} is invalid"
        );
    }
    $actor = [
        'benutzer' => $user,
        'kuerzel' => $code,
        'funktion' => $function,
        'rolle' => $role,
    ];
    if (is_int($assignmentId)) {
        $actor['duty_assignment_id'] = $assignmentId;
    }
    return $actor;
}

function message_db_publish_duty_assignments(array $assignments): void
{
    foreach ($assignments as $key => $assignmentId) {
        if (
            !is_string($key)
            || preg_match('/\A[^|]{1,6}\|[^|]{1,20}\z/D', $key) !== 1
            || !is_int($assignmentId)
            || $assignmentId < 1
        ) {
            throw new RuntimeException(
                'Message duty assignment fixture is invalid'
            );
        }
    }
    message_db_assert(
        putenv(
            'ESTAB_TEST_MESSAGE_DUTY_ASSIGNMENTS='
            . json_encode(
                $assignments,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            )
        ),
        'Could not publish message duty assignments to workers'
    );
}

function message_db_password(): string
{
    foreach (['ESTAB_TEST_DB_PASSWORD', 'ESTAB_DB_PASSWORD'] as $name) {
        $password = getenv($name);
        if ($password !== false && $password !== '') {
            return $password;
        }
    }
    $secretFile = getenv('ESTAB_DB_PASSWORD_FILE');
    if ($secretFile !== false && is_readable($secretFile)) {
        return trim((string) file_get_contents($secretFile));
    }
    return '';
}

/** @return array{server: string, user: string, password: string, datenbank: string} */
function message_db_config(): array
{
    $host = getenv('ESTAB_TEST_DB_HOST') ?: (getenv('ESTAB_DB_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('ESTAB_TEST_DB_PORT') ?: (getenv('ESTAB_DB_PORT') ?: 3306));
    return [
        'server' => $host . ':' . $port,
        'user' => getenv('ESTAB_TEST_DB_USER') ?: (getenv('ESTAB_DB_USER') ?: 'estab'),
        'password' => message_db_password(),
        'datenbank' => getenv('ESTAB_TEST_DB_NAME') ?: (getenv('ESTAB_DB_NAME') ?: 'estab'),
    ];
}

function message_db_drop_fixtures(array $config, array $tables): void
{
    try {
        $connection = estab_message_connect($config);
        foreach (array_reverse($tables) as $table) {
            $connection->query('DROP TABLE IF EXISTS ' . estab_message_table($table));
        }
        estab_auth_close($connection);
    } catch (Throwable) {
        // Cleanup must not hide the original test failure.
    }
}

function message_db_set_account_blocked(
    mysqli $connection,
    string $code,
    bool $blocked
): void {
    $statement = estab_message_execute(
        $connection,
        'UPDATE `nv_benutzer` SET `estab_gesperrt` = ?'
            . ' WHERE BINARY `kuerzel` = BINARY ?',
        [$blocked ? 1 : 0, $code]
    );
    $statement->close();
}

function message_db_wait_for_barrier(string $barrier): void
{
    $deadline = microtime(true) + 15.0;
    while (!is_file($barrier)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Message worker barrier timed out');
        }
        usleep(10_000);
    }
}

function message_db_worker(array $arguments): never
{
    $mode = (string) ($arguments[0] ?? '');
    $barrier = (string) ($arguments[1] ?? '');
    $workerKey = (string) ($arguments[2] ?? '');
    $messageTable = (string) ($arguments[3] ?? '');
    $secondary = (string) ($arguments[4] ?? '');
    $scopeTable = (string) ($arguments[5] ?? '');
    $config = message_db_config();
    $connection = estab_message_connect($config);
    file_put_contents($barrier . '.' . $workerKey . '.ready', 'ready', LOCK_EX);
    message_db_wait_for_barrier($barrier);

    try {
        switch ($mode) {
            case 'numbered':
                $stored = estab_message_insert_numbered(
                    $connection,
                    $config['datenbank'],
                    $messageTable,
                    $secondary,
                    false,
                    [
                        '01_medium' => 'Fu',
                        '01_zeichen' => 'state1',
                        '05_gegenstelle' => 'Leitstelle-' . $workerKey,
                        '06_befweg' => '',
                        '06_befwegausw' => '',
                        '10_anschrift' => 'FORGED-INCOMING-' . $workerKey,
                        '12_betreff' => 'Eingang ' . $workerKey,
                        '12_inhalt' => 'parallel-' . $workerKey,
                        '13_abseinheit' => '',
                        '16_empf' => 'S1_rt',
                        'x00_status' => 8,
                        'x01_abschluss' => 't',
                        'x02_sperre' => 'f',
                        'x03_sperruser' => '',
                    ],
                    null,
                    message_db_event(
                        'created',
                        'Concurrency S2',
                        'state1',
                        'S2',
                        'Stab',
                        null,
                        8,
                        ['worker' => $workerKey]
                    )
                );
                echo json_encode($stored, JSON_THROW_ON_ERROR), "\n";
                break;

            case 'state':
                estab_message_state_set(
                    $connection,
                    $messageTable,
                    $secondary,
                    'read',
                    '2026-07-23 12:00:00',
                    $scopeTable
                );
                echo "1\n";
                break;

            case 'ldf-save':
                $routeEntryId = estab_message_positive_id($scopeTable);
                $success = estab_message_update_locked_operator_stage(
                    $connection,
                    $messageTable,
                    $secondary,
                    message_db_actor(
                        'Concurrency LdF',
                        'ldf001',
                        'LdF',
                        'Fernmelder'
                    ),
                    'A',
                    1,
                    [
                        '02_zeit' => '2026-07-23 12:30:00',
                        '02_zeichen' => 'ldf001',
                        '05_gegenstelle' => 'Florian 1',
                        'estab_fernmeldeplan_eintrag_id' => $routeEntryId,
                        'x00_status' => 2,
                        'x02_sperre' => 'f',
                        'x03_sperruser' => '',
                    ],
                    message_db_event(
                        'ldf_dispatched',
                        'Concurrency LdF',
                        'ldf001',
                        'LdF',
                        'Fernmelder',
                        1,
                        2,
                        ['worker' => $workerKey]
                    )
                );
                echo $success ? "1\n" : "0\n";
                break;

            case 'aw-save':
                $success = estab_message_update_locked_operator_stage(
                    $connection,
                    $messageTable,
                    $secondary,
                    message_db_actor(
                        'Concurrency A/W',
                        'aw0001',
                        'A/W',
                        'Fernmelder'
                    ),
                    'A',
                    2,
                    [
                        '03_datum' => '2026-07-23 12:34:00',
                        '03_zeichen' => 'aw0001',
                        'x00_status' => 8,
                        'x01_abschluss' => 't',
                        'x02_sperre' => 'f',
                        'x03_sperruser' => '',
                    ],
                    message_db_event(
                        'aw_transported',
                        'Concurrency A/W',
                        'aw0001',
                        'A/W',
                        'Fernmelder',
                        2,
                        8,
                        [
                            'worker' => $workerKey,
                            'transport_route_confirmed' => true,
                        ]
                    )
                );
                echo $success ? "1\n" : "0\n";
                break;

            default:
                throw new InvalidArgumentException('Unknown message worker mode');
        }
    } finally {
        estab_auth_close($connection);
    }
    exit(0);
}

/**
 * @return array{process: resource, pipes: array<int, resource>, key: string}
 */
function message_db_start_worker(
    string $mode,
    string $barrier,
    string $workerKey,
    string $messageTable,
    string $secondary,
    string $scopeTable = ''
): array {
    $command = [
        PHP_BINARY,
        '-d',
        'auto_prepend_file=',
        __FILE__,
        '--worker',
        $mode,
        $barrier,
        $workerKey,
        $messageTable,
        $secondary,
        $scopeTable,
    ];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    message_db_assert(is_resource($process), 'Could not start message worker');
    return ['process' => $process, 'pipes' => $pipes, 'key' => $workerKey];
}

function message_db_wait_until_ready(
    string $barrier,
    array $workers
): void {
    $deadline = microtime(true) + 10.0;
    foreach ($workers as $worker) {
        $readyFile = $barrier . '.' . $worker['key'] . '.ready';
        while (!is_file($readyFile)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Concurrent message workers did not become ready');
            }
            usleep(10_000);
        }
    }
}

function message_db_finish_worker(array $worker): string
{
    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    foreach ($worker['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $exitCode = proc_close($worker['process']);
    message_db_assert(
        $exitCode === 0,
        'Message worker ' . $worker['key'] . ' failed: ' . trim((string) $stderr)
    );
    return trim((string) $stdout);
}

function message_db_open_barrier(string $barrier): void
{
    message_db_assert(
        file_put_contents($barrier, 'go', LOCK_EX) !== false,
        'Could not open message worker barrier'
    );
}

if (($argv[1] ?? '') === '--worker') {
    try {
        message_db_worker(array_slice($argv, 2, 6));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

set_time_limit(90);
$config = message_db_config();
$token = strtolower(bin2hex(random_bytes(5)));
$messageTable = 'nv_nachrichten';
$stateTable = 'estab_test_state_' . getmypid() . '_' . $token;
$tables = [$stateTable];
$barriers = [];
$previousIncidentId = null;
$testIncidentId = null;
$permissionModeIncidentId = null;
$commandPostName = 'Führungsstelle Nachrichten ' . $token;

foreach ($tables as $table) {
    message_db_assert(strlen($table) <= 64, 'Fixture identifier is too long');
}
register_shutdown_function(
    static function () use (
        $config,
        $tables,
        &$barriers,
        &$previousIncidentId,
        &$testIncidentId,
        &$permissionModeIncidentId
    ): void {
        if (is_int($testIncidentId) && $testIncidentId > 0) {
            try {
                $restoreConnection = estab_message_connect($config);
                $status = estab_incident_status($restoreConnection);
                $activeIncidentId = (int) (
                    $status['active_einsatz_id'] ?? 0
                );
                if (
                    $activeIncidentId === $testIncidentId
                    || (
                        is_int($permissionModeIncidentId)
                        && $activeIncidentId === $permissionModeIncidentId
                    )
                ) {
                    if (is_int($previousIncidentId) && $previousIncidentId > 0) {
                        $previousIncident = estab_incident_find(
                            $restoreConnection,
                            $previousIncidentId
                        );
                        estab_incident_activate(
                            $restoreConnection,
                            $previousIncidentId,
                            (int) $status['revision'],
                            'message-concurrency-cleanup',
                            is_array($previousIncident)
                                && ($previousIncident['estab_permission_mode'] ?? null)
                                    === ESTAB_PERMISSION_MODE_LOOSE
                        );
                    } else {
                        estab_incident_deactivate(
                            $restoreConnection,
                            $activeIncidentId,
                            (int) $status['revision'],
                            'message-concurrency-cleanup'
                        );
                    }
                }
                estab_auth_close($restoreConnection);
            } catch (Throwable) {
                // CI destroys the ephemeral data volume; cleanup is best effort.
            }
        }
        message_db_drop_fixtures($config, $tables);
        foreach ($barriers as $barrier) {
            @unlink($barrier);
            foreach (['a', 'b', 'writer'] as $workerKey) {
                @unlink($barrier . '.' . $workerKey . '.ready');
            }
        }
    }
);

$connection = null;
try {
    message_db_drop_fixtures($config, $tables);
    $connection = estab_message_connect($config);
    $initialStatus = estab_incident_status($connection);
    $previousIncidentId = is_int($initialStatus['active_einsatz_id'])
        ? $initialStatus['active_einsatz_id']
        : null;
    $createdIncident = estab_incident_create(
        $connection,
        [
            'kennung' => 'CI-MSG-' . strtoupper($token),
            'name' => 'Message concurrency ' . $token,
            'beginn' => date('Y-m-d\TH:i'),
            'organisation' => 'eStab CI',
            'fuehrungsstellenname' => $commandPostName,
            'einsatzleitung' => 'Automatisierte Nachrichtenprüfung',
            'beschreibung' => 'Ephemerer kanonischer Nachrichten-Datenraum',
            'metadaten' => json_encode(
                ['test' => 'message_concurrency', 'token' => $token],
                JSON_THROW_ON_ERROR
            ),
        ],
        'message-concurrency',
        true,
        (int) $initialStatus['revision']
    );
    $testIncidentId = (int) $createdIncident['einsatz_id'];
    $stateSql = 'CREATE TABLE ' . estab_message_table($stateTable) . ' ('
        . ' `lfd` BIGINT NOT NULL AUTO_INCREMENT,'
        . ' `nachnum` BIGINT NOT NULL,'
        . ' `gelesen` DATETIME NULL DEFAULT NULL,'
        . ' PRIMARY KEY (`lfd`),'
        . ' KEY `idx_nachnum` (`nachnum`)'
        . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    message_db_assert($connection->query($stateSql), 'Could not create state fixture');

    // A deterministic active S6 route is part of the canonical outgoing
    // fixture. Production stage writes receive only its immutable entry ID
    // and must derive both medium and readable path under the incident lock.
    $fixtureUsers = [
        ['state1', 'S2', 'Stab', 'Concurrency S2'],
        ['si0001', 'Si', 'Stab', 'Concurrency Si'],
        ['s60001', 'S6', 'Stab', 'Concurrency S6'],
        ['ldf001', 'LdF', 'Fernmelder', 'Concurrency LdF'],
        ['aw0001', 'A/W', 'Fernmelder', 'Concurrency A/W'],
        ['staff1', 'S1', 'Stab', 'Concurrency staff'],
        ['next01', 'S1', 'Stab', 'Concurrency successor'],
    ];
    foreach ($fixtureUsers as [$code, $function, $role, $name]) {
        $fixturePassword = password_hash(
            'message concurrency fixture ' . $code,
            PASSWORD_DEFAULT
        );
        message_db_assert(
            is_string($fixturePassword),
            'Could not hash message fixture password'
        );
        $fixtureUserInsert = estab_message_execute(
            $connection,
            'INSERT IGNORE INTO `nv_benutzer`'
                . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`,'
                . ' `aktiv`, `estab_gesperrt`, `password`)'
                . ' VALUES (?, ?, ?, ?, ?, 1, 0, ?)',
            [
                $name,
                $code,
                $function,
                $role,
                'message-concurrency-' . $code,
                $fixturePassword,
            ]
        );
        $fixtureUserInsert->close();
    }
    $initialShift = estab_dv_create_shift(
        $connection,
        $testIncidentId,
        'Message concurrency initial shift',
        null,
        'message-concurrency'
    );
    $initialShiftId = (int) $initialShift['dienstschicht_id'];
    $historicalAssignments = [];
    foreach (array_slice($fixtureUsers, 0, 6) as [$code, $function]) {
        $hat = estab_dv_assign_hat(
            $connection,
            $testIncidentId,
            $initialShiftId,
            $code,
            $function,
            'message-concurrency'
        );
        estab_dv_accept_hat(
            $connection,
            $testIncidentId,
            (int) $hat['dienstbesetzung_id'],
            $code
        );
        $historicalAssignments[$code . '|' . $function] =
            (int) $hat['dienstbesetzung_id'];
    }
    estab_dv_activate_initial_shift(
        $connection,
        $testIncidentId,
        $initialShiftId,
        'message-concurrency'
    );
    message_db_publish_duty_assignments($historicalAssignments);
    $stateIdentity = message_db_actor(
        'Concurrency S2',
        'state1',
        'S2',
        'Stab'
    );
    $staffIdentity = message_db_actor(
        'Concurrency staff',
        'staff1',
        'S1',
        'Stab'
    );
    $fixtureActor = 's60001';
    $fixtureS6AssignmentId = (int) (
        $historicalAssignments[$fixtureActor . '|S6'] ?? 0
    );
    $planId = estab_dv_with_sql_authority_context(
        $connection,
        $fixtureS6AssignmentId,
        null,
        static function () use (
            $connection,
            $testIncidentId,
            $token,
            $fixtureActor
        ): int {
            $planInsert = estab_message_execute(
                $connection,
                'INSERT INTO `nv_fernmeldeplaene`'
                    . ' (`einsatz_id`, `version`, `einsatzbezeichnung`,'
                    . ' `herkunft`, `gueltig_ab`, `gueltig_bis`,'
                    . ' `betriebsleitung`, `bemerkungen`, `erstellt_von`)'
                    . ' VALUES (?, 1, ?, ?, NOW() - INTERVAL 1 DAY,'
                    . ' NOW() + INTERVAL 1 DAY, ?, ?, ?)',
                [
                    $testIncidentId,
                    'Concurrency ' . $token,
                    'CI message concurrency',
                    'S6 CI',
                    'Canonical outgoing race route',
                    $fixtureActor,
                ]
            );
            try {
                return (int) $connection->insert_id;
            } finally {
                $planInsert->close();
            }
        }
    );
    message_db_assert($planId > 0, 'Could not create S6 plan fixture');
    $routeInsert = estab_message_execute(
        $connection,
        'INSERT INTO `nv_fernmeldeplan_eintraege`'
            . ' (`fernmeldeplan_id`, `sortierung`, `betriebsstelle`,'
            . ' `erreichbarkeit`, `medium`, `kanal`, `bandlage`, `verkehrsform`,'
            . ' `besondere_vermerke`, `bemerkungen`)'
            . " VALUES (?, 1, 'CI Betriebsstelle', 'Florian 1', 'Fu',"
            . " 'Kanal 404', 'G/U', 'Gegenverkehr', '', '')",
        [$planId]
    );
    $telecomEntryId = (int) $connection->insert_id;
    $routeInsert->close();
    message_db_assert(
        $telecomEntryId > 0,
        'Could not create S6 route fixture'
    );
    $routeBInsert = estab_message_execute(
        $connection,
        'INSERT INTO `nv_fernmeldeplan_eintraege`'
            . ' (`fernmeldeplan_id`, `sortierung`, `betriebsstelle`,'
            . ' `erreichbarkeit`, `medium`, `kanal`, `bandlage`, `verkehrsform`,'
            . ' `besondere_vermerke`, `bemerkungen`)'
            . " VALUES (?, 2, 'CI Ersatzstelle', 'Florian 2', 'Fu',"
            . " 'Kanal 505', 'O/U', 'Wechselverkehr', '', 'Redisposition B')",
        [$planId]
    );
    $telecomEntryBId = (int) $connection->insert_id;
    $routeBInsert->close();
    message_db_assert(
        $telecomEntryBId > 0 && $telecomEntryBId !== $telecomEntryId,
        'Could not create distinct S6 redisposition route'
    );
    $planActivate = estab_dv_with_sql_authority_context(
        $connection,
        $fixtureS6AssignmentId,
        null,
        static fn (): mysqli_stmt => estab_message_execute(
            $connection,
            "UPDATE `nv_fernmeldeplaene` SET `status` = 'AKTIV',"
                . ' `freigegeben_am` = NOW(6), `freigegeben_von` = ?'
                . ' WHERE `fernmeldeplan_id` = ? AND `einsatz_id` = ?'
                . " AND `status` = 'ENTWURF'",
            [$fixtureActor, $planId, $testIncidentId]
        )
    );
    message_db_assert(
        $planActivate->affected_rows === 1,
        'Could not activate S6 plan fixture'
    );
    $planActivate->close();

    // Two empty-table writers must reserve distinct sequential paper numbers.
    $numberBarrier = sys_get_temp_dir() . '/estab-message-number-' . $token;
    $barriers[] = $numberBarrier;
    $numberWorkers = [
        message_db_start_worker('numbered', $numberBarrier, 'a', $messageTable, 'E'),
        message_db_start_worker('numbered', $numberBarrier, 'b', $messageTable, 'E'),
    ];
    message_db_wait_until_ready($numberBarrier, $numberWorkers);
    message_db_open_barrier($numberBarrier);
    $numberResults = array_map(
        static fn (array $worker): array =>
            json_decode(message_db_finish_worker($worker), true, 8, JSON_THROW_ON_ERROR),
        $numberWorkers
    );
    $numbers = array_map(static fn (array $row): int => (int) $row['number'], $numberResults);
    sort($numbers);
    message_db_assert($numbers === [1, 2], 'Parallel message numbers collided');
    message_db_assert(
        estab_message_query_int(
            $connection,
            'SELECT COUNT(DISTINCT `04_nummer`) FROM '
                . estab_message_table($messageTable)
                . ' WHERE `einsatz_id` = ?',
            [$testIncidentId]
        ) === 2,
        'Parallel numbered rows are not distinct'
    );
    message_db_assert(
        estab_message_query_int(
            $connection,
            'SELECT COUNT(*) FROM '
                . estab_message_table($messageTable)
                . ' WHERE `einsatz_id` = ?'
                . " AND `12_inhalt` LIKE 'parallel-%'"
                . ' AND BINARY `10_anschrift` = BINARY ?',
            [$testIncidentId, $commandPostName]
        ) === 2,
        'Incoming repository writes trusted forged local destinations'
    );

    $staleIncidentRejected = false;
    try {
        estab_message_insert_numbered(
            $connection,
            $config['datenbank'],
            $messageTable,
            'E',
            false,
            [
                '12_inhalt' => 'stale-incident-probe',
                '16_empf' => 'S1_rt',
                'x00_status' => 1,
                'x01_abschluss' => 'f',
                'x02_sperre' => 'f',
                'x03_sperruser' => '',
            ],
            null,
            message_db_event(
                'created',
                'Concurrency A/W',
                'aw0001',
                'A/W',
                'Fernmelder',
                null,
                1,
                ['fixture' => 'stale-incident']
            ),
            null,
            $testIncidentId + 1000000
        );
    } catch (EstabIncidentConflictException) {
        $staleIncidentRejected = true;
    }
    message_db_assert(
        $staleIncidentRejected
            && estab_message_query_int(
                $connection,
                'SELECT COUNT(*) FROM ' . estab_message_table($messageTable)
                    . ' WHERE `12_inhalt` = ?',
                ['stale-incident-probe']
            ) === 0,
        'Stale message form was reassigned to the newly active incident'
    );

    // Even without a schema UNIQUE key, concurrent state writers produce one
    // logical row, and list reads defend against old duplicate rows.
    $stateStored = estab_message_insert_numbered(
        $connection,
        $config['datenbank'],
        $messageTable,
        'E',
        false,
        [
            '12_inhalt' => 'state fixture',
            '16_empf' => 'S1_rt',
            'x00_status' => 8,
            'x01_abschluss' => 't',
            'x02_sperre' => 'f',
            'x03_sperruser' => '',
        ],
        null,
        message_db_event(
            'created',
            'Concurrency S2',
            'state1',
            'S2',
            'Stab',
            null,
            8,
            ['fixture' => 'state']
        )
    );
    $stateMessageId = (int) $stateStored['id'];
    $stateBarrier = sys_get_temp_dir() . '/estab-message-state-' . $token;
    $barriers[] = $stateBarrier;
    $stateWorkers = [
        message_db_start_worker(
            'state',
            $stateBarrier,
            'a',
            $stateTable,
            (string) $stateMessageId,
            $messageTable
        ),
        message_db_start_worker(
            'state',
            $stateBarrier,
            'b',
            $stateTable,
            (string) $stateMessageId,
            $messageTable
        ),
    ];
    message_db_wait_until_ready($stateBarrier, $stateWorkers);
    message_db_open_barrier($stateBarrier);
    foreach ($stateWorkers as $worker) {
        message_db_assert(message_db_finish_worker($worker) === '1', 'State worker failed');
    }
    message_db_assert(
        estab_message_query_int(
            $connection,
            'SELECT COUNT(*) FROM ' . estab_message_table($stateTable)
                . ' WHERE `nachnum` = ?',
            [$stateMessageId]
        ) === 1,
        'Concurrent state writes created duplicates'
    );
    message_db_assert(
        estab_message_state_ids($connection, $messageTable, $stateTable) === [$stateMessageId],
        'State ID read is not logically distinct'
    );
    message_db_assert(
        !estab_message_state_set_for_recipient(
            $connection,
            $messageTable,
            $stateTable,
            $stateMessageId,
            $stateIdentity,
            'read',
            '2026-07-23 12:01:00'
        ),
        'Foreign recipient obtained message state'
    );
    message_db_assert(
        estab_message_state_unset_for_recipient(
            $connection,
            $messageTable,
            $stateTable,
            $stateMessageId,
            $staffIdentity
        ),
        'Recipient could not remove message state'
    );
    $blockedStateSetRejected = false;
    message_db_set_account_blocked($connection, 'staff1', true);
    try {
        try {
            estab_message_state_set_for_recipient(
                $connection,
                $messageTable,
                $stateTable,
                $stateMessageId,
                $staffIdentity,
                'read',
                '2026-07-23 12:02:00'
            );
        } catch (EstabDvPermissionException) {
            $blockedStateSetRejected = true;
        }
    } finally {
        message_db_set_account_blocked($connection, 'staff1', false);
    }
    message_db_assert(
        $blockedStateSetRejected
            && estab_message_query_int(
                $connection,
                'SELECT COUNT(*) FROM ' . estab_message_table($stateTable)
                    . ' WHERE `nachnum` = ?',
                [$stateMessageId]
            ) === 0,
        'Blocked account wrote recipient state from a stale identity'
    );
    message_db_assert(
        estab_message_state_set_for_recipient(
            $connection,
            $messageTable,
            $stateTable,
            $stateMessageId,
            $staffIdentity,
            'read',
            '2026-07-23 12:03:00'
        ),
        'Unblocked recipient could not restore message state'
    );
    $blockedStateUnsetRejected = false;
    message_db_set_account_blocked($connection, 'staff1', true);
    try {
        try {
            estab_message_state_unset_for_recipient(
                $connection,
                $messageTable,
                $stateTable,
                $stateMessageId,
                $staffIdentity
            );
        } catch (EstabDvPermissionException) {
            $blockedStateUnsetRejected = true;
        }
    } finally {
        message_db_set_account_blocked($connection, 'staff1', false);
    }
    message_db_assert(
        $blockedStateUnsetRejected
            && estab_message_query_int(
                $connection,
                'SELECT COUNT(*) FROM ' . estab_message_table($stateTable)
                    . ' WHERE `nachnum` = ?',
                [$stateMessageId]
            ) === 1,
        'Blocked account removed recipient state from a stale identity'
    );
    message_db_assert(
        estab_message_state_unset_for_recipient(
            $connection,
            $messageTable,
            $stateTable,
            $stateMessageId,
            $staffIdentity
        ),
        'Unblocked recipient could not remove restored message state'
    );

    // One real outgoing row proves the complete formal review and transport
    // boundary: staff draft -> Si approval -> LdF -> A/W.
    $raceStored = estab_message_insert_numbered(
        $connection,
        $config['datenbank'],
        $messageTable,
        'A',
        false,
        [
            '02_zeit' => null,
            '02_zeichen' => '',
            '03_datum' => null,
            '03_zeichen' => '',
            '05_gegenstelle' => '',
            '06_befweg' => '',
            '06_befwegausw' => '',
            '10_anschrift' => 'THW Musterstadt',
            '12_inhalt' => 'staged operator race fixture',
            '13_abseinheit' => 'FORGED-OUTGOING-CREATE',
            '14_zeichen' => 'staff1',
            '14_funktion' => 'S1',
            '15_quitdatum' => null,
            '15_quitzeichen' => '',
            '16_empf' => 'S1_rt,S2_bl',
            'x00_status' => 4,
            'x01_abschluss' => 'f',
            'x02_sperre' => 'f',
            'x03_sperruser' => '',
        ],
        null,
        message_db_event(
            'created',
            'Concurrency staff',
            'staff1',
            'S1',
            'Stab',
            null,
            4,
            ['fixture' => 'operator-race']
        )
    );
    $raceMessageId = (int) $raceStored['id'];
    message_db_assert(
        !estab_message_state_set_for_recipient(
            $connection,
            $messageTable,
            $stateTable,
            $raceMessageId,
            $stateIdentity,
            'read',
            '2026-07-23 12:04:00'
        ),
        'Foreign outgoing recipient obtained state before terminal status'
    );
    message_db_assert(
        estab_message_state_set_for_recipient(
            $connection,
            $messageTable,
            $stateTable,
            $raceMessageId,
            $staffIdentity,
            'read',
            '2026-07-23 12:04:00'
        )
            && estab_message_state_unset_for_recipient(
                $connection,
                $messageTable,
                $stateTable,
                $raceMessageId,
                $staffIdentity
            ),
        'Outgoing function author lost nonterminal state access'
    );
    $siIdentity = message_db_actor(
        'Concurrency Si',
        'si0001',
        'Si',
        'Stab'
    );
    $successorIdentity = message_db_actor(
        'Concurrency successor',
        'next01',
        'S1',
        'Stab'
    );
    $foreignFunctionIdentity = [
        'benutzer' => 'Concurrency foreign function',
        'kuerzel' => 'other1',
        'funktion' => 'S2',
        'rolle' => 'Stab',
    ];
    $ldfIdentity = message_db_actor(
        'Concurrency LdF',
        'ldf001',
        'LdF',
        'Fernmelder'
    );
    $awIdentity = message_db_actor(
        'Concurrency A/W',
        'aw0001',
        'A/W',
        'Fernmelder'
    );
    $statusFourRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($statusFourRow)
            && (string) $statusFourRow['13_abseinheit'] === $commandPostName,
        'Status-4 fixture lost its incident-authoritative local sender'
    );
    message_db_assert(
        estab_message_object_allowed($siIdentity, 'viewer-review', $statusFourRow),
        'Si was denied the real status-4 outgoing row'
    );
    message_db_assert(
        !estab_message_object_allowed(
            $ldfIdentity,
            'telecommunications-lead-edit',
            $statusFourRow
        ),
        'LdF was allowed to bypass formal Si approval'
    );

    // Evidence validation happens after the domain UPDATE but before commit.
    // A malformed event must roll both changes back.
    $invalidEvidenceRolledBack = false;
    try {
        estab_message_update_pending_review(
            $connection,
            $messageTable,
            $raceMessageId,
            [
                '15_quitdatum' => '2026-07-23 12:20:00',
                '15_quitzeichen' => 'si0001',
                'x00_status' => 1,
            ],
            message_db_event(
                'Invalid-Type',
                'Concurrency Si',
                'si0001',
                'Si',
                'Stab',
                4,
                1
            )
        );
    } catch (EstabMessageEvidenceInputException) {
        $invalidEvidenceRolledBack = true;
    }
    message_db_assert(
        $invalidEvidenceRolledBack,
        'Invalid evidence did not abort the formal review transaction'
    );
    $rolledBackRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($rolledBackRow)
            && (int) $rolledBackRow['x00_status'] === 4
            && estab_datetime_is_unset($rolledBackRow['15_quitdatum'])
            && (string) $rolledBackRow['15_quitzeichen'] === '',
        'A failed evidence insert did not roll back the message transition'
    );

    message_db_assert(
        estab_message_update_pending_review(
            $connection,
            $messageTable,
            $raceMessageId,
            [
                '15_quitdatum' => '2026-07-23 12:20:00',
                '15_quitzeichen' => 'si0001',
                '17_vermerke' => 'Anschrift präzisieren',
                'x00_status' => 10,
                'x01_abschluss' => 'f',
            ],
            message_db_event(
                'si_returned',
                'Concurrency Si',
                'si0001',
                'Si',
                'Stab',
                4,
                10,
                ['reason' => 'Anschrift präzisieren']
            )
        ),
        'Si could not return the outgoing message'
    );
    $returnedRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($returnedRow)
            && estab_message_object_allowed(
                $successorIdentity,
                'staff-correction',
                $returnedRow
            )
            && !estab_message_object_allowed(
                $foreignFunctionIdentity,
                'staff-correction',
                $returnedRow
            ),
        'Returned task did not pass exclusively to the same staff function'
    );
    $forgedAttachmentRejected = false;
    try {
        estab_message_resubmit_returned_outgoing(
            $connection,
            $messageTable,
            $raceMessageId,
            'staff1',
            'S1',
            [
                '12_anhang' => 'NICHTVORHANDEN.pdf;',
                '14_zeichen' => 'staff1',
                '14_funktion' => 'S1',
                'x00_status' => 4,
            ],
            message_db_event(
                'author_resubmitted',
                'Concurrency staff',
                'staff1',
                'S1',
                'Stab',
                10,
                4,
                ['probe' => 'forged-attachment']
            ),
            'nv_anhang'
        );
    } catch (EstabIncidentConflictException) {
        $forgedAttachmentRejected = true;
    }
    $afterForgedAttachment = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        $forgedAttachmentRejected
            && is_array($afterForgedAttachment)
            && (int) $afterForgedAttachment['x00_status'] === 10
            && (string) $afterForgedAttachment['12_anhang'] === '',
        'Forged correction attachment was accepted or partially persisted'
    );
    $successorShift = estab_dv_create_shift(
        $connection,
        $testIncidentId,
        'Message concurrency successor shift',
        $initialShiftId,
        'message-concurrency'
    );
    $successorShiftId = (int) $successorShift['dienstschicht_id'];
    $successorConfirmingAssignmentId = 0;
    $successorDutyAssignments = [];
    foreach ([
        ['state1', 'S2'],
        ['si0001', 'Si'],
        ['s60001', 'S6'],
        ['ldf001', 'LdF'],
        ['aw0001', 'A/W'],
        ['next01', 'S1'],
    ] as [$code, $function]) {
        $hat = estab_dv_assign_hat(
            $connection,
            $testIncidentId,
            $successorShiftId,
            $code,
            $function,
            'message-concurrency'
        );
        if ($code === 'next01') {
            $successorConfirmingAssignmentId = (int) $hat['dienstbesetzung_id'];
        }
        $successorDutyAssignments[$code . '|' . $function] =
            (int) $hat['dienstbesetzung_id'];
        estab_dv_accept_hat(
            $connection,
            $testIncidentId,
            (int) $hat['dienstbesetzung_id'],
            $code
        );
    }
    message_db_assert(
        $successorConfirmingAssignmentId > 0,
        'Successor S1 assignment is missing'
    );
    $handoverRequestId = estab_dv_initiate_handover_shift(
        $connection,
        $testIncidentId,
        $initialShiftId,
        $successorShiftId,
        'Nachrichtenlage und offener Korrekturauftrag vollständig übergeben.',
        $historicalAssignments['state1|S2'],
        [
            'benutzer' => 'Concurrency S2',
            'kuerzel' => 'state1',
            'funktion' => 'S2',
            'rolle' => 'Stab',
        ],
        'message-concurrency'
    );
    message_db_assert(
        $handoverRequestId > 0,
        'Shift handover request was not created'
    );
    estab_dv_confirm_handover_shift(
        $connection,
        $testIncidentId,
        $handoverRequestId,
        $successorConfirmingAssignmentId,
        [
            'benutzer' => 'Concurrency successor',
            'kuerzel' => 'next01',
            'funktion' => 'S1',
            'rolle' => 'Stab',
        ]
    );
    message_db_publish_duty_assignments($successorDutyAssignments);
    $siIdentity = message_db_actor(
        'Concurrency Si',
        'si0001',
        'Si',
        'Stab'
    );
    $successorIdentity = message_db_actor(
        'Concurrency successor',
        'next01',
        'S1',
        'Stab'
    );
    $ldfIdentity = message_db_actor(
        'Concurrency LdF',
        'ldf001',
        'LdF',
        'Fernmelder'
    );
    $awIdentity = message_db_actor(
        'Concurrency A/W',
        'aw0001',
        'A/W',
        'Fernmelder'
    );
    message_db_assert(
        estab_message_resubmit_returned_outgoing(
            $connection,
            $messageTable,
            $raceMessageId,
            'next01',
            'S1',
            [
                '10_anschrift' => 'THW Musterstadt korrigiert',
                '12_inhalt' => 'staged operator race fixture',
                '13_abseinheit' => 'FORGED-OUTGOING-RESUBMIT',
                '14_zeichen' => 'next01',
                '14_funktion' => 'S1',
                '15_quitdatum' => null,
                '15_quitzeichen' => '',
                'x00_status' => 4,
                'x01_abschluss' => 'f',
                'x02_sperre' => 'f',
                'x03_sperruser' => '',
            ],
            message_db_event(
                'author_resubmitted',
                'Concurrency successor',
                'next01',
                'S1',
                'Stab',
                10,
                4,
                ['correction' => 'address']
            )
        ),
        'Fixed-function successor account could not resubmit the returned task'
    );
    $resubmittedRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($resubmittedRow)
            && (int) $resubmittedRow['x00_status'] === 4
            && (string) $resubmittedRow['14_zeichen'] === 'next01'
            && (string) $resubmittedRow['14_funktion'] === 'S1'
            && (string) $resubmittedRow['13_abseinheit']
                === $commandPostName
            && estab_datetime_is_unset($resubmittedRow['15_quitdatum'])
            && (string) $resubmittedRow['15_quitzeichen'] === '',
        'Shift successor resubmission lost responsibility or review reset'
    );
    message_db_assert(
        estab_message_update_pending_review(
            $connection,
            $messageTable,
            $raceMessageId,
            [
                '15_quitdatum' => '2026-07-23 12:21:00',
                '15_quitzeichen' => 'si0001',
                'x00_status' => 1,
            ],
            message_db_event(
                'si_approved',
                'Concurrency Si',
                'si0001',
                'Si',
                'Stab',
                4,
                1,
                ['formal_check' => 'approved']
            )
        ),
        'Si could not formally approve the outgoing message'
    );
    $statusOneRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($statusOneRow)
            && (int) $statusOneRow['x00_status'] === 1
            && (string) $statusOneRow['15_quitzeichen'] === 'si0001'
            && estab_message_object_allowed(
                $ldfIdentity,
                'telecommunications-lead-edit',
                $statusOneRow
            )
            && !estab_message_object_allowed(
                $awIdentity,
                'telecommunications-edit',
                $statusOneRow
            ),
        'Formal Si approval did not hand the row exclusively to LdF'
    );
    message_db_assert(
        !estab_message_acquire_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            $awIdentity,
            'A',
            2
        ),
        'A/W acquired a status-2 lock before the LdF decision'
    );
    $blockedAcquireRejected = false;
    message_db_set_account_blocked($connection, 'ldf001', true);
    try {
        try {
            estab_message_acquire_operator_stage_lock(
                $connection,
                $messageTable,
                $raceMessageId,
                $ldfIdentity,
                'A',
                1
            );
        } catch (EstabDvPermissionException) {
            $blockedAcquireRejected = true;
        }
    } finally {
        message_db_set_account_blocked($connection, 'ldf001', false);
    }
    $afterBlockedAcquire = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        $blockedAcquireRejected
            && is_array($afterBlockedAcquire)
            && (string) $afterBlockedAcquire['x02_sperre'] === 'f'
            && (string) $afterBlockedAcquire['x03_sperruser'] === '',
        'Blocked account acquired an operator lock from a stale identity'
    );
    message_db_assert(
        estab_message_acquire_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            $ldfIdentity,
            'A',
            1
        ),
        'LdF could not acquire the status-1 lock'
    );
    $blockedReleaseRejected = false;
    message_db_set_account_blocked($connection, 'ldf001', true);
    try {
        try {
            estab_message_release_operator_stage_lock(
                $connection,
                $messageTable,
                $raceMessageId,
                'A',
                1,
                $ldfIdentity
            );
        } catch (EstabDvPermissionException) {
            $blockedReleaseRejected = true;
        }
    } finally {
        message_db_set_account_blocked($connection, 'ldf001', false);
    }
    $afterBlockedRelease = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        $blockedReleaseRejected
            && is_array($afterBlockedRelease)
            && (string) $afterBlockedRelease['x02_sperre'] === 't'
            && (string) $afterBlockedRelease['x03_sperruser'] === 'ldf001',
        'Blocked account released an operator lock from a stale identity'
    );
    $foreignLeadReleaseRejected = false;
    try {
        estab_message_release_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            'A',
            1,
            $awIdentity
        );
    } catch (EstabDvPermissionException) {
        $foreignLeadReleaseRejected = true;
    }
    message_db_assert(
        $foreignLeadReleaseRejected,
        'STRICT did not reject A/W at the LdF-owned status-1 lock'
    );
    $lockedStatusOneRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($lockedStatusOneRow)
            && estab_message_object_allowed(
                $ldfIdentity,
                'telecommunications-lead-outgoing-save',
                $lockedStatusOneRow
            )
            && !estab_message_object_allowed(
                $awIdentity,
                'telecommunications-save',
                $lockedStatusOneRow
            ),
        'The status-1 lock was not bound exclusively to LdF'
    );
    message_db_assert(
        !estab_message_update_locked_operator_stage(
            $connection,
            $messageTable,
            $raceMessageId,
            $awIdentity,
            'A',
            2,
            [
                '03_datum' => '2026-07-23 12:33:00',
                '03_zeichen' => 'aw0001',
                'x00_status' => 8,
            ],
            message_db_event(
                'aw_transported',
                'Concurrency A/W',
                'aw0001',
                'A/W',
                'Fernmelder',
                2,
                8,
                [
                    'probe' => 'premature',
                    'transport_route_confirmed' => true,
                ]
            )
        ),
        'A/W saved before the LdF stage was complete'
    );

    $missingCallsignRejected = false;
    try {
        estab_message_update_locked_operator_stage(
            $connection,
            $messageTable,
            $raceMessageId,
            $ldfIdentity,
            'A',
            1,
            [
                '02_zeit' => '2026-07-23 12:29:00',
                '02_zeichen' => 'ldf001',
                '05_gegenstelle' => '',
                'estab_fernmeldeplan_eintrag_id' => $telecomEntryId,
                'x00_status' => 2,
                'x01_abschluss' => 'f',
                'x02_sperre' => 'f',
                'x03_sperruser' => '',
            ],
            message_db_event(
                'ldf_dispatched',
                'Concurrency LdF',
                'ldf001',
                'LdF',
                'Fernmelder',
                1,
                2,
                ['probe' => 'missing-callsign']
            )
        );
    } catch (EstabDvInputException) {
        $missingCallsignRejected = true;
    }
    $afterMissingCallsign = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        $missingCallsignRejected
            && is_array($afterMissingCallsign)
            && (int) $afterMissingCallsign['x00_status'] === 1
            && estab_datetime_is_unset($afterMissingCallsign['02_zeit'])
            && (string) $afterMissingCallsign['02_zeichen'] === ''
            && (string) $afterMissingCallsign['05_gegenstelle'] === ''
            && (int) ($afterMissingCallsign[
                'estab_fernmeldeplan_eintrag_id'
            ] ?? 0) === 0
            && (string) $afterMissingCallsign['x02_sperre'] === 't'
            && (string) $afterMissingCallsign['x03_sperruser'] === 'ldf001',
        'Repository accepted or partially persisted an empty LdF callsign'
    );

    // Both parallel LdF requests carry the same once-valid lock and form data.
    // The first one advances to status 2; the second must become a stale save.
    $ldfRaceBarrier = sys_get_temp_dir() . '/estab-message-ldf-race-' . $token;
    $barriers[] = $ldfRaceBarrier;
    $ldfRaceWorkers = [
        message_db_start_worker(
            'ldf-save',
            $ldfRaceBarrier,
            'a',
            $messageTable,
            (string) $raceMessageId,
            (string) $telecomEntryId
        ),
        message_db_start_worker(
            'ldf-save',
            $ldfRaceBarrier,
            'b',
            $messageTable,
            (string) $raceMessageId,
            (string) $telecomEntryId
        ),
    ];
    message_db_wait_until_ready($ldfRaceBarrier, $ldfRaceWorkers);
    message_db_open_barrier($ldfRaceBarrier);
    $ldfSaveResults = array_map(
        static fn (array $worker): string => message_db_finish_worker($worker),
        $ldfRaceWorkers
    );
    message_db_assert(
        count(array_filter(
            $ldfSaveResults,
            static fn (string $result): bool => $result === '1'
        )) === 1
            && count(array_filter(
                $ldfSaveResults,
                static fn (string $result): bool => $result === '0'
            )) === 1,
        'Parallel LdF saves did not have exactly one winner'
    );
    $statusTwoRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($statusTwoRow)
            && (int) $statusTwoRow['x00_status'] === 2
            && (string) $statusTwoRow['02_zeit'] === '2026-07-23 12:30:00'
            && (string) $statusTwoRow['02_zeichen'] === 'ldf001'
            && (string) $statusTwoRow['05_gegenstelle'] === 'Florian 1'
            && (string) $statusTwoRow['06_befweg']
                === 'CI Betriebsstelle · Florian 1 · Kanal 404 · G/U · Gegenverkehr'
            && (string) $statusTwoRow['01_medium'] === 'Fu'
            && (int) $statusTwoRow['estab_fernmeldeplan_eintrag_id']
                === $telecomEntryId
            && estab_datetime_is_unset($statusTwoRow['03_datum'])
            && (string) $statusTwoRow['03_zeichen'] === ''
            && (string) $statusTwoRow['x02_sperre'] === 'f'
            && (string) $statusTwoRow['x03_sperruser'] === '',
        'Winning LdF save did not persist exactly one status-2 decision'
    );
    message_db_assert(
        !estab_message_object_allowed(
            $ldfIdentity,
            'telecommunications-lead-edit',
            $statusTwoRow
        )
            && estab_message_object_allowed(
                $awIdentity,
                'telecommunications-edit',
                $statusTwoRow
            ),
        'The real status-2 row was not handed exclusively from LdF to A/W'
    );
    message_db_assert(
        !estab_message_update_locked_operator_stage(
            $connection,
            $messageTable,
            $raceMessageId,
            $ldfIdentity,
            'A',
            1,
            [
                '02_zeit' => '2026-07-23 12:31:00',
                '02_zeichen' => 'ldf001',
                '05_gegenstelle' => 'Florian 1',
                'x00_status' => 2,
            ],
            message_db_event(
                'ldf_dispatched',
                'Concurrency LdF',
                'ldf001',
                'LdF',
                'Fernmelder',
                1,
                2,
                [
                    'probe' => 'stale',
                    'transport_route_confirmed' => true,
                ]
            )
        ),
        'A stale LdF save changed the completed status-1 stage'
    );
    message_db_assert(
        !estab_message_acquire_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            $ldfIdentity,
            'A',
            1
        ),
        'LdF reacquired its completed status-1 stage'
    );
    message_db_assert(
        estab_message_acquire_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            $awIdentity,
            'A',
            2
        ),
        'A/W could not acquire the LdF-completed status-2 row'
    );
    $foreignAwReleaseRejected = false;
    try {
        estab_message_release_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            'A',
            2,
            $ldfIdentity
        );
    } catch (EstabDvPermissionException) {
        $foreignAwReleaseRejected = true;
    }
    message_db_assert(
        $foreignAwReleaseRejected,
        'STRICT did not reject LdF at the A/W-owned status-2 lock'
    );
    $lockedStatusTwoRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($lockedStatusTwoRow)
            && estab_message_object_allowed(
                $awIdentity,
                'telecommunications-save',
                $lockedStatusTwoRow
            )
            && !estab_message_object_allowed(
                $ldfIdentity,
                'telecommunications-lead-outgoing-save',
                $lockedStatusTwoRow
            ),
        'The status-2 lock was not bound exclusively to A/W'
    );

    $missingReturnReasonRejected = false;
    try {
        estab_message_update_locked_operator_stage(
            $connection,
            $messageTable,
            $raceMessageId,
            $awIdentity,
            'A',
            2,
            [
                '02_zeit' => null,
                '02_zeichen' => '',
                '03_datum' => null,
                '03_zeichen' => '',
                'x00_status' => 1,
                'x01_abschluss' => 'f',
                'x02_sperre' => 'f',
                'x03_sperruser' => '',
            ],
            message_db_event(
                'aw_transport_returned',
                'Concurrency A/W',
                'aw0001',
                'A/W',
                'Fernmelder',
                2,
                1,
                ['transport_return_reason' => '']
            )
        );
    } catch (EstabDvInputException) {
        $missingReturnReasonRejected = true;
    }
    message_db_assert(
        $missingReturnReasonRejected,
        'A/W returned an impossible route without a mandatory reason'
    );
    message_db_assert(
        estab_message_update_locked_operator_stage(
            $connection,
            $messageTable,
            $raceMessageId,
            $awIdentity,
            'A',
            2,
            [
                '02_zeit' => null,
                '02_zeichen' => '',
                '03_datum' => null,
                '03_zeichen' => '',
                'x00_status' => 1,
                'x01_abschluss' => 'f',
                'x02_sperre' => 'f',
                'x03_sperruser' => '',
            ],
            message_db_event(
                'aw_transport_returned',
                'Concurrency A/W',
                'aw0001',
                'A/W',
                'Fernmelder',
                2,
                1,
                [
                    'transport_return_reason' =>
                        'Route A ist an der Gegenstelle ausgefallen',
                ]
            )
        ),
        'A/W could not return an impossible route to LdF'
    );
    $returnedToLdfRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($returnedToLdfRow)
            && (int) $returnedToLdfRow['x00_status'] === 1
            && estab_datetime_is_unset($returnedToLdfRow['02_zeit'])
            && (string) $returnedToLdfRow['02_zeichen'] === ''
            && (int) $returnedToLdfRow['estab_fernmeldeplan_eintrag_id']
                === $telecomEntryId
            && (string) $returnedToLdfRow['06_befweg']
                === 'CI Betriebsstelle · Florian 1 · Kanal 404 · G/U · Gegenverkehr'
            && (string) $returnedToLdfRow['x02_sperre'] === 'f'
            && (string) $returnedToLdfRow['x03_sperruser'] === '',
        'A/W return lost route-A evidence or did not reopen the LdF stage'
    );

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $directRouteReplacementRejected = false;
    try {
        $forgedRoute = estab_message_execute(
            $connection,
            'UPDATE ' . estab_message_table($messageTable)
                . ' SET `estab_fernmeldeplan_eintrag_id` = ?,'
                . " `01_medium` = 'Fu', `06_befwegausw` = 'Fu',"
                . " `06_befweg` = ?"
                . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?',
            [
                $telecomEntryBId,
                'CI Ersatzstelle · Florian 2 · Kanal 505 · O/U · Wechselverkehr',
                $raceMessageId,
                $testIncidentId,
            ]
        );
        $forgedRoute->close();
    } catch (mysqli_sql_exception) {
        $directRouteReplacementRejected = true;
    }
    message_db_assert(
        $directRouteReplacementRejected,
        'Route trigger allowed replacement outside locked LdF redisposition'
    );
    message_db_assert(
        estab_message_acquire_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            $ldfIdentity,
            'A',
            1
        ),
        'LdF could not acquire the returned redistribution stage'
    );
    message_db_assert(
        estab_message_update_locked_operator_stage(
            $connection,
            $messageTable,
            $raceMessageId,
            $ldfIdentity,
            'A',
            1,
            [
                '02_zeit' => '2026-07-23 12:32:00',
                '02_zeichen' => 'ldf001',
                '05_gegenstelle' => 'Florian 2',
                'estab_fernmeldeplan_eintrag_id' => $telecomEntryBId,
                'x00_status' => 2,
                'x01_abschluss' => 'f',
                'x02_sperre' => 'f',
                'x03_sperruser' => '',
            ],
            message_db_event(
                'ldf_dispatched',
                'Concurrency LdF',
                'ldf001',
                'LdF',
                'Fernmelder',
                1,
                2,
                ['redisposition_probe' => 'route-b']
            )
        ),
        'LdF could not redispose the message to active route B'
    );
    $redisposedRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($redisposedRow)
            && (int) $redisposedRow['x00_status'] === 2
            && (int) $redisposedRow['estab_fernmeldeplan_eintrag_id']
                === $telecomEntryBId
            && (string) $redisposedRow['06_befweg']
                === 'CI Ersatzstelle · Florian 2 · Kanal 505 · O/U · Wechselverkehr',
        'Locked LdF redisposition did not replace route A with route B'
    );
    message_db_assert(
        estab_message_acquire_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            $awIdentity,
            'A',
            2
        ),
        'A/W could not acquire the redisposed route-B stage'
    );

    // The same stale-form race is repeated at the A/W stage. Advancing to
    // status 8 must invalidate the second in-flight save at the SQL predicate.
    $awRaceBarrier = sys_get_temp_dir() . '/estab-message-aw-race-' . $token;
    $barriers[] = $awRaceBarrier;
    $awRaceWorkers = [
        message_db_start_worker(
            'aw-save',
            $awRaceBarrier,
            'a',
            $messageTable,
            (string) $raceMessageId
        ),
        message_db_start_worker(
            'aw-save',
            $awRaceBarrier,
            'b',
            $messageTable,
            (string) $raceMessageId
        ),
    ];
    message_db_wait_until_ready($awRaceBarrier, $awRaceWorkers);
    message_db_open_barrier($awRaceBarrier);
    $awSaveResults = array_map(
        static fn (array $worker): string => message_db_finish_worker($worker),
        $awRaceWorkers
    );
    message_db_assert(
        count(array_filter(
            $awSaveResults,
            static fn (string $result): bool => $result === '1'
        )) === 1
            && count(array_filter(
                $awSaveResults,
                static fn (string $result): bool => $result === '0'
            )) === 1,
        'Parallel A/W saves did not have exactly one winner'
    );
    $completedRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(
        is_array($completedRow)
            && (int) $completedRow['x00_status'] === 8
            && (string) $completedRow['x01_abschluss'] === 't'
            && (string) $completedRow['03_datum'] === '2026-07-23 12:34:00'
            && (string) $completedRow['03_zeichen'] === 'aw0001'
            && (string) $completedRow['02_zeichen'] === 'ldf001'
            && (string) $completedRow['02_zeit'] === '2026-07-23 12:32:00'
            && (string) $completedRow['05_gegenstelle'] === 'Florian 2'
            && (string) $completedRow['06_befweg']
                === 'CI Ersatzstelle · Florian 2 · Kanal 505 · O/U · Wechselverkehr'
            && (string) $completedRow['01_medium'] === 'Fu'
            && (int) $completedRow['estab_fernmeldeplan_eintrag_id']
                === $telecomEntryBId
            && (string) $completedRow['x02_sperre'] === 'f'
            && (string) $completedRow['x03_sperruser'] === '',
        'Winning A/W save did not complete exactly one immutable LdF decision'
    );
    message_db_assert(
        !estab_message_update_locked_operator_stage(
            $connection,
            $messageTable,
            $raceMessageId,
            $awIdentity,
            'A',
            2,
            [
                '03_datum' => '2026-07-23 12:35:00',
                '03_zeichen' => 'aw0001',
                'x00_status' => 8,
            ],
            message_db_event(
                'aw_transported',
                'Concurrency A/W',
                'aw0001',
                'A/W',
                'Fernmelder',
                2,
                8,
                ['probe' => 'stale']
            )
        ),
        'A stale A/W save changed the completed status-2 stage'
    );

    $eventStatement = estab_message_execute(
        $connection,
        'SELECT `event_type` FROM `nv_nachrichten_ereignisse`'
            . ' WHERE `einsatz_id` = ? AND `message_id` = ?'
            . ' ORDER BY `event_id`',
        [$testIncidentId, $raceMessageId]
    );
    try {
        $raceEventTypes = array_map(
            static fn (array $row): string => (string) $row['event_type'],
            $eventStatement->get_result()->fetch_all(MYSQLI_ASSOC)
        );
    } finally {
        $eventStatement->close();
    }
    message_db_assert(
        $raceEventTypes === [
            'created',
            'si_returned',
            'author_resubmitted',
            'si_approved',
            'ldf_dispatched',
            'aw_transport_returned',
            'ldf_dispatched',
            'aw_transported',
        ],
        'Message evidence does not exactly match the completed workflow'
    );
    $routeEvidenceRows = estab_message_query_rows(
        $connection,
        'SELECT `event_type`, `field_snapshot`'
            . ' FROM `nv_nachrichten_ereignisse`'
            . ' WHERE `message_id` = ?'
            . " AND `event_type` IN ('ldf_dispatched',"
            . " 'aw_transport_returned') ORDER BY `event_id`",
        [$raceMessageId]
    );
    $firstRouteSnapshot = json_decode(
        (string) ($routeEvidenceRows[0]['field_snapshot'] ?? ''),
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    $returnRouteSnapshot = json_decode(
        (string) ($routeEvidenceRows[1]['field_snapshot'] ?? ''),
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    $secondRouteSnapshot = json_decode(
        (string) ($routeEvidenceRows[2]['field_snapshot'] ?? ''),
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    message_db_assert(
        count($routeEvidenceRows) === 3
            && ($firstRouteSnapshot['telecom_plan_entry_id'] ?? null)
                === $telecomEntryId
            && ($returnRouteSnapshot['rejected_telecom_plan_entry_id'] ?? null)
                === $telecomEntryId
            && ($returnRouteSnapshot['transport_return_reason'] ?? null)
                === 'Route A ist an der Gegenstelle ausgefallen'
            && ($secondRouteSnapshot['previous_telecom_plan_entry_id'] ?? null)
                === $telecomEntryId
            && ($secondRouteSnapshot['telecom_plan_entry_id'] ?? null)
                === $telecomEntryBId,
        'Route A, return reason, and route B are not preserved in evidence'
    );
    $successorEvidence = estab_message_query_rows(
        $connection,
        'SELECT `field_snapshot` FROM `nv_nachrichten_ereignisse`'
            . " WHERE `message_id` = ? AND `event_type` = 'author_resubmitted'",
        [$raceMessageId]
    );
    $successorSnapshot = json_decode(
        (string) ($successorEvidence[0]['field_snapshot'] ?? ''),
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    message_db_assert(
        ($successorSnapshot['original_author_code'] ?? null) === 'staff1'
            && ($successorSnapshot['original_author_function'] ?? null) === 'S1'
            && ($successorSnapshot['responsible_author_code'] ?? null) === 'next01'
            && ($successorSnapshot['responsible_author_function'] ?? null) === 'S1',
        'Shift-successor event did not preserve old and new responsibility'
    );
    $evidenceVerification = estab_message_evidence_verify(
        $connection,
        $testIncidentId
    );
    message_db_assert(
        $evidenceVerification['valid'] === true,
        'Hash-linked message evidence verification failed'
    );
    $terminalMutationRejected = false;
    $terminalMutationDetected = false;
    try {
        $tamper = estab_message_execute(
            $connection,
            'UPDATE ' . estab_message_table($messageTable)
                . ' SET `12_inhalt` = ? WHERE `00_lfd` = ?',
            ['direct terminal tamper', $raceMessageId]
        );
        $tamperApplied = $tamper->affected_rows === 1;
        $tamper->close();
        if ($tamperApplied) {
            $tamperedVerification = estab_message_evidence_verify(
                $connection,
                $testIncidentId
            );
            $terminalMutationDetected =
                $tamperedVerification['valid'] === false
                && (int) (
                    $tamperedVerification['terminal_mismatches'] ?? 0
                ) > 0;
            $restore = estab_message_execute(
                $connection,
                'UPDATE ' . estab_message_table($messageTable)
                    . ' SET `12_inhalt` = ? WHERE `00_lfd` = ?',
                ['staged operator race fixture', $raceMessageId]
            );
            message_db_assert(
                $restore->affected_rows === 1,
                'Could not restore deliberate terminal tamper fixture'
            );
            $restore->close();
        }
    } catch (mysqli_sql_exception) {
        $terminalMutationRejected = true;
    }
    message_db_assert(
        $terminalMutationRejected || $terminalMutationDetected,
        'Direct terminal content tampering was neither rejected nor detected'
    );
    message_db_assert(
        estab_message_evidence_verify(
            $connection,
            $testIncidentId
        )['valid'] === true,
        'Terminal evidence did not recover after the controlled tamper probe'
    );

    // Administrative repair and regular allocation share the same advisory
    // namespace. While admin owns it, the writer cannot add a row.
    $adminLock = estab_admin_acquire_counter_lock($connection, $messageTable);
    try {
        $crossBarrier = sys_get_temp_dir() . '/estab-message-cross-' . $token;
        $barriers[] = $crossBarrier;
        $crossWorker = message_db_start_worker(
            'numbered',
            $crossBarrier,
            'writer',
            $messageTable,
            'E'
        );
        message_db_wait_until_ready($crossBarrier, [$crossWorker]);
        message_db_open_barrier($crossBarrier);
        usleep(500_000);
        $workerStatus = proc_get_status($crossWorker['process']);
        message_db_assert(
            ($workerStatus['running'] ?? false) === true,
            'Regular writer bypassed administrator counter lock'
        );
        message_db_assert(
            estab_message_query_int(
                $connection,
                'SELECT COUNT(*) FROM ' . estab_message_table($messageTable)
                    . ' WHERE `einsatz_id` = ?',
                [$testIncidentId]
            ) === 4,
            'Writer changed row count while administrator held counter lock'
        );
    } finally {
        estab_admin_release_counter_lock($connection, $adminLock);
    }
    $crossResult = json_decode(
        message_db_finish_worker($crossWorker),
        true,
        8,
        JSON_THROW_ON_ERROR
    );
    message_db_assert(
        (int) ($crossResult['number'] ?? 0) === 5,
        'Counter namespace probe did not resume with the current maximum'
    );

    // Paper-number recovery is an immutable operational watermark, never a
    // synthetic status-0 message. The next genuine message must allocate
    // strictly after it, while the Fach row count changes only for that real
    // message and both evidence chains remain valid.
    $rowsBeforeRepair = estab_message_query_int(
        $connection,
        'SELECT COUNT(*) FROM ' . estab_message_table($messageTable)
            . ' WHERE `einsatz_id` = ?',
        [$testIncidentId]
    );
    $repairTarget = 50;
    $repairResult = estab_admin_raise_message_counter(
        $connection,
        $messageTable,
        'nv_protokoll',
        'gemeinsam',
        ['ea_nummer' => $repairTarget],
        'message-concurrency-admin'
    );
    message_db_assert(
        ($repairResult['after']['ea_nummer'] ?? null) === $repairTarget
            && estab_message_counter_repair_max(
                $connection,
                $testIncidentId,
                false,
                'E'
            ) === $repairTarget
            && estab_message_query_int(
                $connection,
                'SELECT COUNT(*) FROM '
                    . estab_message_table($messageTable)
                    . ' WHERE `einsatz_id` = ?',
                [$testIncidentId]
            ) === $rowsBeforeRepair,
        'Administrative counter repair created a fachliche message row'
    );
    $postRepair = estab_message_insert_numbered(
        $connection,
        $config['datenbank'],
        $messageTable,
        'E',
        false,
        [
            '12_inhalt' => 'real message after paper counter repair',
            '16_empf' => 'S2_rt',
            'x00_status' => 8,
            'x01_abschluss' => 't',
            'x02_sperre' => 'f',
            'x03_sperruser' => '',
        ],
        null,
        message_db_event(
            'created',
            'Concurrency S2',
            'state1',
            'S2',
            'Stab',
            null,
            8,
            ['probe' => 'after-counter-repair']
        )
    );
    message_db_assert(
        (int) ($postRepair['number'] ?? 0) === $repairTarget + 1
            && estab_message_query_int(
                $connection,
                'SELECT COUNT(*) FROM '
                    . estab_message_table($messageTable)
                    . ' WHERE `einsatz_id` = ? AND `x00_status` = 0',
                [$testIncidentId]
            ) === 0
            && estab_dv_verify_event_chain(
                $connection,
                $testIncidentId
            )['valid'] === true
            && estab_message_evidence_verify(
                $connection,
                $testIncidentId
            )['valid'] === true,
        'Counter watermark did not preserve allocation and evidence validity'
    );

    // A later configuration change must not make a paper watermark disappear.
    // Common repairs constrain both split directions; split repairs in turn
    // constrain the next common allocation.
    $splitRepair = estab_admin_raise_message_counter(
        $connection,
        $messageTable,
        'nv_protokoll',
        'getrennt',
        ['e_nummer' => 60, 'a_nummer' => 70],
        'message-concurrency-admin'
    );
    message_db_assert(
        ($splitRepair['after']['e_nummer'] ?? null) === 60
            && ($splitRepair['after']['a_nummer'] ?? null) === 70
            && estab_message_counter_repair_max(
                $connection,
                $testIncidentId,
                true,
                'E'
            ) === 60
            && estab_message_counter_repair_max(
                $connection,
                $testIncidentId,
                true,
                'A'
            ) === 70
            && estab_message_counter_repair_max(
                $connection,
                $testIncidentId,
                false,
                'E'
            ) === 70,
        'Counter repair watermark was lost across numbering-mode change'
    );
    $postModeChange = estab_message_insert_numbered(
        $connection,
        $config['datenbank'],
        $messageTable,
        'A',
        false,
        [
            '12_inhalt' => 'real message after numbering-mode change',
            '16_empf' => 'S2_rt',
            'x00_status' => 8,
            'x01_abschluss' => 't',
            'x02_sperre' => 'f',
            'x03_sperruser' => '',
        ],
        null,
        message_db_event(
            'created',
            'Concurrency S2',
            'state1',
            'S2',
            'Stab',
            null,
            8,
            ['probe' => 'after-numbering-mode-change']
        )
    );
    message_db_assert(
        (int) ($postModeChange['number'] ?? 0) === 71
            && estab_dv_verify_event_chain(
                $connection,
                $testIncidentId
            )['valid'] === true
            && estab_message_evidence_verify(
                $connection,
                $testIncidentId
            )['valid'] === true,
        'Next real number reused a watermark after numbering-mode change'
    );

    // Keep STRICT and LOOSE as independent incident identities: permission
    // mode becomes immutable as soon as operational records exist. The STRICT
    // message proves the formal assignment boundary; two messages created in
    // LOOSE prove explicit personal additional-function handling.
    $strictModeStatus = estab_incident_status($connection);
    message_db_assert(
        (int) ($strictModeStatus['active_einsatz_id'] ?? 0) === $testIncidentId
            && ($strictModeStatus['estab_permission_mode'] ?? null) === 'STRICT',
        'Message permission-mode fixture did not start in STRICT'
    );
    estab_permission_context_set_from_incident($strictModeStatus);
    $permissionAuthorIdentity = $successorIdentity;
    $createReturnedPermissionFixture = static function (
        int $incidentId,
        string $fixture
    ) use (
        $connection,
        $config,
        $messageTable,
        $permissionAuthorIdentity,
        $siIdentity
    ): int {
        $stored = estab_message_insert_numbered(
            $connection,
            $config['datenbank'],
            $messageTable,
            'A',
            false,
            [
                '02_zeit' => null,
                '02_zeichen' => '',
                '03_datum' => null,
                '03_zeichen' => '',
                '10_anschrift' => 'THW Permission Mode',
                '12_betreff' => 'Berechtigungsmodus ' . $fixture,
                '12_inhalt' => 'permission-mode-' . $fixture,
                '13_abseinheit' => 'FORGED-PERMISSION-MODE',
                '14_zeichen' => $permissionAuthorIdentity['kuerzel'],
                '14_funktion' => $permissionAuthorIdentity['funktion'],
                '15_quitdatum' => null,
                '15_quitzeichen' => '',
                '16_empf' => 'S1_rt,S2_bl',
                'x00_status' => 4,
                'x01_abschluss' => 'f',
                'x02_sperre' => 'f',
                'x03_sperruser' => '',
            ],
            null,
            message_db_event(
                'created',
                $permissionAuthorIdentity['benutzer'],
                $permissionAuthorIdentity['kuerzel'],
                $permissionAuthorIdentity['funktion'],
                $permissionAuthorIdentity['rolle'],
                null,
                4,
                ['fixture' => 'permission-mode-' . $fixture]
            ),
            null,
            $incidentId
        );
        $messageId = (int) $stored['id'];
        message_db_assert(
            estab_message_update_pending_review(
                $connection,
                $messageTable,
                $messageId,
                [
                    '15_quitdatum' => '2026-07-23 15:00:00',
                    '15_quitzeichen' => $siIdentity['kuerzel'],
                    '17_vermerke' => 'Korrektur im Berechtigungsmodus',
                    'x00_status' => 10,
                    'x01_abschluss' => 'f',
                ],
                message_db_event(
                    'si_returned',
                    $siIdentity['benutzer'],
                    $siIdentity['kuerzel'],
                    $siIdentity['funktion'],
                    $siIdentity['rolle'],
                    4,
                    10,
                    ['fixture' => 'permission-mode-' . $fixture]
                ),
                $incidentId
            ),
            'Could not create returned permission-mode fixture ' . $fixture
        );
        return $messageId;
    };
    $strictPermissionGuardMessageId = $createReturnedPermissionFixture(
        $testIncidentId,
        'strict-guard'
    );
    $correctionIdentity = $stateIdentity;
    $crossRoleCorrectionFields = static function (
        string $probe
    ) use (&$correctionIdentity): array {
        return [
            '10_anschrift' => 'THW Permission Mode korrigiert',
            '12_betreff' => 'Berechtigungsmodus korrigiert',
            '12_inhalt' => 'permission-mode-correction-' . $probe,
            '13_abseinheit' => 'FORGED-CROSS-ROLE-SENDER',
            '14_zeichen' => $correctionIdentity['kuerzel'],
            '14_funktion' => $correctionIdentity['funktion'],
            '15_quitdatum' => null,
            '15_quitzeichen' => '',
            'x00_status' => 4,
            'x01_abschluss' => 'f',
            'x02_sperre' => 'f',
            'x03_sperruser' => '',
        ];
    };
    $crossRoleEvent = static function (
        string $probe
    ) use (&$correctionIdentity): array {
        return message_db_event(
            'author_resubmitted',
            $correctionIdentity['benutzer'],
            $correctionIdentity['kuerzel'],
            $correctionIdentity['funktion'],
            $correctionIdentity['rolle'],
            10,
            4,
            ['permission_mode_probe' => $probe]
        );
    };

    $strictReturned = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $strictPermissionGuardMessageId
    );
    message_db_assert(
        is_array($strictReturned)
            && !estab_message_object_allowed(
                $stateIdentity,
                'staff-correction',
                $strictReturned,
                true
            )
            && !estab_message_resubmit_returned_outgoing(
                $connection,
                $messageTable,
                $strictPermissionGuardMessageId,
                $correctionIdentity['kuerzel'],
                $correctionIdentity['funktion'],
                $crossRoleCorrectionFields('strict-before'),
                $crossRoleEvent('strict-before'),
                null,
                null,
                $testIncidentId
            )
            && estab_message_query_int(
                $connection,
                'SELECT COUNT(*) FROM `nv_nachrichten_ereignisse`'
                    . ' WHERE `message_id` = ?'
                    . " AND `event_type` = 'author_resubmitted'",
                [$strictPermissionGuardMessageId]
            ) === 0,
        'STRICT admitted a cross-role returned-message correction'
    );

    $looseStatusBeforeCreate = estab_incident_status($connection);
    $looseCreated = estab_incident_create(
        $connection,
        [
            'kennung' => 'CI-MSG-LOOSE-' . strtoupper($token),
            'name' => 'Message permission LOOSE ' . $token,
            'beginn' => date('Y-m-d\TH:i'),
            'organisation' => 'eStab CI',
            'fuehrungsstellenname' => $commandPostName,
            'einsatzleitung' => 'Automatisierte Nachrichtenprüfung',
            'beschreibung' =>
                'Separater LOOSE-Datenraum für Berechtigungsnachweise',
            'estab_permission_mode' => ESTAB_PERMISSION_MODE_LOOSE,
            'metadaten' => json_encode(
                [
                    'test' => 'message_concurrency_permission_mode',
                    'token' => $token,
                ],
                JSON_THROW_ON_ERROR
            ),
        ],
        'message-permission-mode-test',
        true,
        (int) $looseStatusBeforeCreate['revision'],
        true
    );
    $permissionModeIncidentId = (int) $looseCreated['einsatz_id'];
    $looseIncident = estab_incident_status($connection);
    estab_permission_context_set_from_incident($looseIncident);
    $permissionModeMessageId = $createReturnedPermissionFixture(
        $permissionModeIncidentId,
        'takeover'
    );
    $permissionModeGuardMessageId = $createReturnedPermissionFixture(
        $permissionModeIncidentId,
        'guard'
    );
    $strictRestoredIncident = null;
    try {
        $looseReturned = estab_message_fetch_by_id(
            $connection,
            $messageTable,
            $permissionModeMessageId
        );
        $looseBaseIdentity = estab_dv_require_operational_account(
            $connection,
            $permissionModeIncidentId,
            $stateIdentity
        );
        message_db_assert(
            ($looseIncident['estab_permission_mode'] ?? null) === 'LOOSE'
                && is_array($looseReturned)
                && estab_workflow_identity_for_message_operation(
                    $looseBaseIdentity,
                    'staff-correction',
                    $looseReturned
                ) === null,
            'LOOSE exposed an S1 correction to ungranted S2'
        );
        $grantS1 = estab_message_execute(
            $connection,
            'INSERT INTO `nv_benutzer_zusatzfunktionen`'
                . ' (`benutzer_kuerzel`, `funktion`, `rolle`, `vergeben_von`)'
                . ' VALUES (?, ?, ?, ?)',
            [$stateIdentity['kuerzel'], 'S1', 'Stab', 'message-permission-mode-test']
        );
        message_db_assert(
            $grantS1->affected_rows === 1,
            'Could not grant the explicit S1 correction function'
        );
        $grantS1->close();
        $looseGrantedIdentity = estab_dv_require_operational_account(
            $connection,
            $permissionModeIncidentId,
            $stateIdentity
        );
        $selectedCorrectionIdentity = estab_workflow_identity_for_message_operation(
            $looseGrantedIdentity,
            'staff-correction',
            $looseReturned
        );
        message_db_assert(
            is_array($selectedCorrectionIdentity)
                && ($selectedCorrectionIdentity['funktion'] ?? null) === 'S1'
                && ($selectedCorrectionIdentity['rolle'] ?? null) === 'Stab'
                && ($selectedCorrectionIdentity['authorization_account_function'] ?? null)
                    === 'S2'
                && ($selectedCorrectionIdentity['authorization_route_function'] ?? null)
                    === 'S1',
            'LOOSE did not select the exact explicitly granted S1 actor'
        );
        $correctionIdentity = $selectedCorrectionIdentity;
        message_db_assert(
            estab_message_resubmit_returned_outgoing(
                $connection,
                $messageTable,
                $permissionModeMessageId,
                $correctionIdentity['kuerzel'],
                $correctionIdentity['funktion'],
                $crossRoleCorrectionFields('loose-success'),
                $crossRoleEvent('loose-success'),
                null,
                null,
                $permissionModeIncidentId
            ),
            'LOOSE explicit S1 grant did not permit the exact S1 correction'
        );
        $correctedByCrossRole = estab_message_fetch_by_id(
            $connection,
            $messageTable,
            $permissionModeMessageId
        );
        $takeoverEvidence = estab_message_query_rows(
            $connection,
            'SELECT `actor_user`, `actor_code`, `actor_function`,'
                . ' `from_status`, `to_status`, `field_snapshot`'
                . ' FROM `nv_nachrichten_ereignisse`'
                . ' WHERE `message_id` = ?'
                . " AND `event_type` = 'author_resubmitted'"
                . ' ORDER BY `event_id`',
            [$permissionModeMessageId]
        );
        message_db_assert(
            count($takeoverEvidence) === 1,
            'LOOSE correction did not append exactly one takeover event'
        );
        $takeoverSnapshot = json_decode(
            (string) $takeoverEvidence[0]['field_snapshot'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        message_db_assert(
            is_array($correctedByCrossRole)
                && (int) $correctedByCrossRole['x00_status'] === 4
                && (string) $correctedByCrossRole['14_zeichen'] === 'state1'
                && (string) $correctedByCrossRole['14_funktion'] === 'S1'
                && (string) $correctedByCrossRole['13_abseinheit']
                    === $commandPostName
                && (string) $takeoverEvidence[0]['actor_user']
                    === $stateIdentity['benutzer']
                && (string) $takeoverEvidence[0]['actor_code'] === 'state1'
                && (string) $takeoverEvidence[0]['actor_function'] === 'S1'
                && (int) $takeoverEvidence[0]['from_status'] === 10
                && (int) $takeoverEvidence[0]['to_status'] === 4
                && ($takeoverSnapshot['original_author_code'] ?? null)
                    === $permissionAuthorIdentity['kuerzel']
                && ($takeoverSnapshot['original_author_function'] ?? null)
                    === 'S1'
                && ($takeoverSnapshot['responsible_author_code'] ?? null)
                    === 'state1'
                && ($takeoverSnapshot['responsible_author_function'] ?? null)
                    === 'S1',
            'LOOSE takeover lost actor, original author, or new responsibility evidence'
        );

        message_db_assert(
            !estab_message_resubmit_returned_outgoing(
                $connection,
                $messageTable,
                $permissionModeMessageId,
                $correctionIdentity['kuerzel'],
                $correctionIdentity['funktion'],
                $crossRoleCorrectionFields('wrong-status'),
                $crossRoleEvent('wrong-status'),
                null,
                null,
                $permissionModeIncidentId
            )
                && estab_message_query_int(
                    $connection,
                    'SELECT COUNT(*) FROM `nv_nachrichten_ereignisse`'
                        . ' WHERE `message_id` = ?'
                        . " AND `event_type` = 'author_resubmitted'",
                    [$permissionModeMessageId]
                ) === 1,
            'LOOSE bypassed status 10 or appended evidence for a stale correction'
        );

        $foreignIncidentRejected = false;
        try {
            estab_message_resubmit_returned_outgoing(
                $connection,
                $messageTable,
                $permissionModeGuardMessageId,
                $correctionIdentity['kuerzel'],
                $correctionIdentity['funktion'],
                $crossRoleCorrectionFields('foreign-incident'),
                $crossRoleEvent('foreign-incident'),
                null,
                null,
                $permissionModeIncidentId + 1000000
            );
        } catch (EstabIncidentConflictException) {
            $foreignIncidentRejected = true;
        }
        message_db_assert(
            $foreignIncidentRejected,
            'LOOSE accepted a correction captured for another incident'
        );

        $blockedActorRejected = false;
        message_db_set_account_blocked(
            $connection,
            $stateIdentity['kuerzel'],
            true
        );
        try {
            try {
                estab_dv_require_operational_account(
                    $connection,
                    $permissionModeIncidentId,
                    $stateIdentity
                );
            } catch (EstabDvPermissionException) {
                $blockedActorRejected = true;
            }
        } finally {
            message_db_set_account_blocked(
                $connection,
                $stateIdentity['kuerzel'],
                false
            );
        }
        message_db_assert(
            $blockedActorRejected,
            'LOOSE committed a correction from a blocked account'
        );

        $revokedActorRejected = false;
        $revokeS1 = estab_message_execute(
            $connection,
            'DELETE FROM `nv_benutzer_zusatzfunktionen`'
                . ' WHERE BINARY `benutzer_kuerzel` = BINARY ?'
                . ' AND BINARY `funktion` = BINARY ?',
            [$stateIdentity['kuerzel'], 'S1']
        );
        $grantWasRevoked = $revokeS1->affected_rows === 1;
        $revokeS1->close();
        $identityAfterRevocation = estab_dv_require_operational_account(
            $connection,
            $permissionModeIncidentId,
            $stateIdentity
        );
        $guardForRevocation = estab_message_fetch_by_id(
            $connection,
            $messageTable,
            $permissionModeGuardMessageId
        );
        $revokedActorRejected = is_array($guardForRevocation)
            && estab_workflow_identity_for_message_operation(
                $identityAfterRevocation,
                'staff-correction',
                $guardForRevocation
            ) === null;
        message_db_assert(
            $grantWasRevoked && $revokedActorRejected,
            'Revoked S1 function remained effective for the S2 account'
        );
        $restoreS1Grant = estab_message_execute(
            $connection,
            'INSERT INTO `nv_benutzer_zusatzfunktionen`'
                . ' (`benutzer_kuerzel`, `funktion`, `rolle`, `vergeben_von`)'
                . ' VALUES (?, ?, ?, ?)',
            [$stateIdentity['kuerzel'], 'S1', 'Stab', 'strict-stale-grant-test']
        );
        message_db_assert(
            $restoreS1Grant->affected_rows === 1,
            'Could not restore stale loose grant for the STRICT boundary probe'
        );
        $restoreS1Grant->close();
        $guardAfterNegatives = estab_message_fetch_by_id(
            $connection,
            $messageTable,
            $permissionModeGuardMessageId
        );
        message_db_assert(
            $revokedActorRejected
                && is_array($guardAfterNegatives)
                && (int) $guardAfterNegatives['x00_status'] === 10
                && (string) $guardAfterNegatives['14_zeichen']
                    === $permissionAuthorIdentity['kuerzel']
                && (string) $guardAfterNegatives['14_funktion'] === 'S1'
                && estab_message_query_int(
                    $connection,
                    'SELECT COUNT(*) FROM `nv_nachrichten_ereignisse`'
                        . ' WHERE `message_id` = ?'
                        . " AND `event_type` = 'author_resubmitted'",
                    [$permissionModeGuardMessageId]
                ) === 0,
            'A rejected LOOSE correction changed the message or its evidence'
        );
    } finally {
        $modeBeforeRestore = estab_incident_status($connection);
        if (
            (int) ($modeBeforeRestore['active_einsatz_id'] ?? 0)
                === $permissionModeIncidentId
        ) {
            $strictRestoredIncident = estab_incident_activate(
                $connection,
                $testIncidentId,
                (int) $modeBeforeRestore['revision'],
                'message-permission-mode-test'
            );
        } else {
            $strictRestoredIncident = $modeBeforeRestore;
        }
        estab_permission_context_set_from_incident($strictRestoredIncident);
    }

    $correctionIdentity = $stateIdentity;
    $strictOperationalRejected = false;
    try {
        estab_dv_require_operational_account(
            $connection,
            $testIncidentId,
            $stateIdentity
        );
    } catch (EstabDvPermissionException) {
        $strictOperationalRejected = true;
    }
    $strictGuardRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $strictPermissionGuardMessageId
    );
    message_db_assert(
        ($strictRestoredIncident['estab_permission_mode'] ?? null) === 'STRICT'
            && is_array($strictGuardRow)
            && $strictOperationalRejected
            && estab_message_query_int(
                $connection,
                'SELECT COUNT(*) FROM `nv_benutzer_zusatzfunktionen`'
                    . ' WHERE BINARY `benutzer_kuerzel` = BINARY ?'
                    . ' AND BINARY `funktion` = BINARY ?',
                [$stateIdentity['kuerzel'], 'S1']
            ) === 1
            && !estab_message_object_allowed(
                $stateIdentity,
                'staff-correction',
                $strictGuardRow,
                true
            )
            && !estab_message_resubmit_returned_outgoing(
                $connection,
                $messageTable,
                $strictPermissionGuardMessageId,
                $stateIdentity['kuerzel'],
                $stateIdentity['funktion'],
                $crossRoleCorrectionFields('strict-restored'),
                $crossRoleEvent('strict-restored'),
                null,
                null,
                $testIncidentId
            )
            && estab_message_query_int(
                $connection,
                'SELECT COUNT(*) FROM `nv_einsatz_ereignisse`'
                    . ' WHERE `einsatz_id` = ?'
                    . " AND `aktion` = 'berechtigung_geaendert'",
                [$testIncidentId]
            ) === 0
            && estab_message_query_int(
                $connection,
                'SELECT COUNT(*) FROM `nv_einsatz_ereignisse`'
                    . ' WHERE `einsatz_id` = ?'
                    . " AND `aktion` = 'berechtigung_geaendert'",
                [$permissionModeIncidentId]
            ) === 0
            && estab_message_evidence_verify(
                $connection,
                $testIncidentId
            )['valid'] === true
            && estab_message_evidence_verify(
                $connection,
                $permissionModeIncidentId
            )['valid'] === true,
        'Reactivating the immutable STRICT fixture did not ignore the stale '
            . 'LOOSE S1 grant'
    );
    $cleanupS1Grant = estab_message_execute(
        $connection,
        'DELETE FROM `nv_benutzer_zusatzfunktionen`'
            . ' WHERE BINARY `benutzer_kuerzel` = BINARY ?'
            . ' AND BINARY `funktion` = BINARY ?',
        [$stateIdentity['kuerzel'], 'S1']
    );
    $cleanupS1Grant->close();

    // A completed evidence row rejects even an idempotent-looking generic
    // update; corrections must be new, explicitly linked records.
    message_db_assert(
        !estab_message_update(
            $connection,
            $messageTable,
            $stateMessageId,
            ['12_inhalt' => 'state fixture'],
            message_db_event(
                'administrative_update',
                'Concurrency admin',
                'admin1',
                'S1',
                'Stab',
                8,
                8,
                ['probe' => 'idempotent']
            )
        ),
        'Completed message accepted a generic administrative update'
    );
    $missingId = 999999999;
    message_db_assert(
        !estab_message_update(
            $connection,
            $messageTable,
            $missingId,
            ['12_inhalt' => 'missing'],
            message_db_event(
                'administrative_update',
                'Concurrency admin',
                'admin1',
                'S1',
                'Stab',
                8,
                8,
                ['probe' => 'missing']
            )
        ),
        'Missing message update reported success'
    );

    echo "Message concurrency integration: OK\n";
} finally {
    putenv('ESTAB_TEST_MESSAGE_DUTY_ASSIGNMENTS');
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}
