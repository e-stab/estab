<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/message_repository.php';
require_once __DIR__ . '/../../app/admin_operations.php';

function message_db_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
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
                        '12_inhalt' => 'parallel-' . $workerKey,
                        '16_empf' => 'S1_rt',
                        'x02_sperre' => 'f',
                        'x03_sperruser' => '',
                    ]
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
                $success = estab_message_update_locked_operator_stage(
                    $connection,
                    $messageTable,
                    $secondary,
                    'ldf001',
                    'A',
                    1,
                    [
                        '02_zeit' => '2026-07-23 12:30:00',
                        '02_zeichen' => 'ldf001',
                        '05_gegenstelle' => 'Florian 1',
                        '06_befweg' => 'Kanal 404',
                        '06_befwegausw' => 'Fu',
                        'x00_status' => 2,
                        'x02_sperre' => 'f',
                        'x03_sperruser' => '',
                    ]
                );
                echo $success ? "1\n" : "0\n";
                break;

            case 'aw-save':
                $success = estab_message_update_locked_operator_stage(
                    $connection,
                    $messageTable,
                    $secondary,
                    'aw0001',
                    'A',
                    2,
                    [
                        '03_datum' => '2026-07-23 12:34:00',
                        '03_zeichen' => 'aw0001',
                        'x00_status' => 8,
                        'x01_abschluss' => 't',
                        'x02_sperre' => 'f',
                        'x03_sperruser' => '',
                    ]
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
$messageTable = 'estab_test_msg_' . getmypid() . '_' . $token;
$stateTable = 'estab_test_state_' . getmypid() . '_' . $token;
$tables = [$messageTable, $stateTable];
$barriers = [];

foreach ($tables as $table) {
    message_db_assert(strlen($table) <= 64, 'Fixture identifier is too long');
}
register_shutdown_function(
    static function () use ($config, $tables, &$barriers): void {
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
    $messageSql = 'CREATE TABLE ' . estab_message_table($messageTable) . ' ('
        . ' `00_lfd` BIGINT NOT NULL AUTO_INCREMENT,'
        . ' `einsatz_id` BIGINT UNSIGNED NULL DEFAULT NULL,'
        . " `02_zeit` DATETIME NULL DEFAULT NULL,"
        . " `02_zeichen` VARCHAR(6) NOT NULL DEFAULT '',"
        . " `03_datum` DATETIME NULL DEFAULT NULL,"
        . " `03_zeichen` VARCHAR(6) NOT NULL DEFAULT '',"
        . " `04_richtung` CHAR(1) NOT NULL DEFAULT '',"
        . ' `04_nummer` BIGINT NOT NULL DEFAULT 0,'
        . " `05_gegenstelle` VARCHAR(128) NOT NULL DEFAULT '',"
        . " `06_befweg` VARCHAR(128) NOT NULL DEFAULT '',"
        . " `06_befwegausw` VARCHAR(3) NOT NULL DEFAULT '',"
        . " `12_inhalt` TEXT NOT NULL,"
        . " `15_quitdatum` DATETIME NULL DEFAULT NULL,"
        . " `15_quitzeichen` VARCHAR(6) NOT NULL DEFAULT '',"
        . " `16_empf` VARCHAR(255) NOT NULL DEFAULT '',"
        . ' `x00_status` SMALLINT NOT NULL DEFAULT 8,'
        . " `x01_abschluss` CHAR(1) NOT NULL DEFAULT 'f',"
        . " `x02_sperre` CHAR(1) NOT NULL DEFAULT 'f',"
        . " `x03_sperruser` VARCHAR(6) NOT NULL DEFAULT '',"
        . ' PRIMARY KEY (`00_lfd`),'
        . ' KEY `idx_direction_number` (`04_richtung`, `04_nummer`)'
        . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    message_db_assert($connection->query($messageSql), 'Could not create message fixture');
    $stateSql = 'CREATE TABLE ' . estab_message_table($stateTable) . ' ('
        . ' `lfd` BIGINT NOT NULL AUTO_INCREMENT,'
        . ' `nachnum` BIGINT NOT NULL,'
        . ' `gelesen` DATETIME NULL DEFAULT NULL,'
        . ' PRIMARY KEY (`lfd`),'
        . ' KEY `idx_nachnum` (`nachnum`)'
        . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    message_db_assert($connection->query($stateSql), 'Could not create state fixture');

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
            'SELECT COUNT(DISTINCT `04_nummer`) FROM ' . estab_message_table($messageTable)
        ) === 2,
        'Parallel numbered rows are not distinct'
    );

    // Even without a schema UNIQUE key, concurrent state writers produce one
    // logical row, and list reads defend against old duplicate rows.
    $stateMessageId = estab_message_insert(
        $connection,
        $messageTable,
        [
            '04_richtung' => 'E',
            '04_nummer' => 50,
            '12_inhalt' => 'state fixture',
            '16_empf' => 'S1_rt',
            'x02_sperre' => 'f',
            'x03_sperruser' => '',
        ]
    );
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
            'S10',
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
            'S1'
        ),
        'Recipient could not remove message state'
    );

    // One real outgoing row proves the complete stage boundary. Status 1 is
    // exclusively offered to LdF; A/W can reach it only after the LdF save
    // atomically records its decision and advances the row to status 2.
    $raceMessageId = estab_message_insert(
        $connection,
        $messageTable,
        [
            '02_zeit' => null,
            '02_zeichen' => '',
            '03_datum' => null,
            '03_zeichen' => '',
            '04_richtung' => 'A',
            '04_nummer' => 51,
            '05_gegenstelle' => '',
            '06_befweg' => '',
            '06_befwegausw' => '',
            '12_inhalt' => 'staged operator race fixture',
            '16_empf' => 'S1_rt',
            'x00_status' => 1,
            'x01_abschluss' => 'f',
            'x02_sperre' => 'f',
            'x03_sperruser' => '',
        ]
    );
    $ldfIdentity = [
        'kuerzel' => 'ldf001',
        'funktion' => 'LdF',
        'rolle' => 'Fernmelder',
    ];
    $awIdentity = [
        'kuerzel' => 'aw0001',
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
    ];
    $statusOneRow = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $raceMessageId
    );
    message_db_assert(is_array($statusOneRow), 'Status-1 fixture disappeared');
    message_db_assert(
        estab_message_object_allowed(
            $ldfIdentity,
            'telecommunications-lead-edit',
            $statusOneRow
        ),
        'LdF was denied the real status-1 row'
    );
    message_db_assert(
        !estab_message_object_allowed(
            $awIdentity,
            'telecommunications-edit',
            $statusOneRow
        ),
        'A/W was allowed to bypass LdF on the real status-1 row'
    );
    message_db_assert(
        !estab_message_acquire_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            'aw0001',
            'A',
            2
        ),
        'A/W acquired a status-2 lock before the LdF decision'
    );
    message_db_assert(
        estab_message_acquire_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            'ldf001',
            'A',
            1
        ),
        'LdF could not acquire the status-1 lock'
    );
    message_db_assert(
        !estab_message_release_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            'A',
            1,
            'aw0001'
        ),
        'A/W released the LdF-owned status-1 lock'
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
            'aw0001',
            'A',
            2,
            [
                '03_datum' => '2026-07-23 12:33:00',
                '03_zeichen' => 'aw0001',
                'x00_status' => 8,
            ]
        ),
        'A/W saved before the LdF stage was complete'
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
            (string) $raceMessageId
        ),
        message_db_start_worker(
            'ldf-save',
            $ldfRaceBarrier,
            'b',
            $messageTable,
            (string) $raceMessageId
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
            && (string) $statusTwoRow['06_befweg'] === 'Kanal 404'
            && (string) $statusTwoRow['06_befwegausw'] === 'Fu'
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
            'ldf001',
            'A',
            1,
            [
                '02_zeit' => '2026-07-23 12:31:00',
                '02_zeichen' => 'ldf001',
                'x00_status' => 2,
            ]
        ),
        'A stale LdF save changed the completed status-1 stage'
    );
    message_db_assert(
        !estab_message_acquire_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            'ldf001',
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
            'aw0001',
            'A',
            2
        ),
        'A/W could not acquire the LdF-completed status-2 row'
    );
    message_db_assert(
        !estab_message_release_operator_stage_lock(
            $connection,
            $messageTable,
            $raceMessageId,
            'A',
            2,
            'ldf001'
        ),
        'LdF released the A/W-owned status-2 lock'
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
            && (string) $completedRow['05_gegenstelle'] === 'Florian 1'
            && (string) $completedRow['06_befweg'] === 'Kanal 404'
            && (string) $completedRow['06_befwegausw'] === 'Fu'
            && (string) $completedRow['x02_sperre'] === 'f'
            && (string) $completedRow['x03_sperruser'] === '',
        'Winning A/W save did not complete exactly one immutable LdF decision'
    );
    message_db_assert(
        !estab_message_update_locked_operator_stage(
            $connection,
            $messageTable,
            $raceMessageId,
            'aw0001',
            'A',
            2,
            [
                '03_datum' => '2026-07-23 12:35:00',
                '03_zeichen' => 'aw0001',
                'x00_status' => 8,
            ]
        ),
        'A stale A/W save changed the completed status-2 stage'
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
        (int) ($crossResult['number'] ?? 0) === 52,
        'Counter namespace probe did not resume with the current maximum'
    );

    // Generic writes distinguish an idempotent match from a lost target.
    message_db_assert(
        estab_message_update(
            $connection,
            $messageTable,
            $stateMessageId,
            ['12_inhalt' => 'state fixture']
        ),
        'Idempotent message update was not verified'
    );
    $missingId = 999999999;
    message_db_assert(
        !estab_message_update(
            $connection,
            $messageTable,
            $missingId,
            ['12_inhalt' => 'missing']
        ),
        'Missing message update reported success'
    );

    echo "Message concurrency integration: OK\n";
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}
