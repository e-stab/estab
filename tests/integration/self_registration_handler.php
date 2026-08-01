<?php

declare(strict_types=1);

define('ESTAB_ASSIGNMENT_POLICY_LOCK_TIMEOUT', 15);
define('ESTAB_DYNAMIC_SCHEMA_LOCK_TIMEOUT_SECONDS', 15);
define('ESTAB_LOGIN_LOCK_TIMEOUT_SECONDS', 15);
define('ESTAB_PASSWORD_POLICY_LOCK_TIMEOUT', 15);
define('ESTAB_SELF_REGISTRATION_LOCK_TIMEOUT', 15);

require_once dirname(__DIR__, 2) . '/app/assignment.php';
require_once dirname(__DIR__, 2) . '/app/self_registration.php';

const ESTAB_SELFREG_HANDLER_DATABASE =
    'estab_self_registration_handler_ci_test';

$assertions = 0;
function selfreg_handler_test_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{server:string,user:string,password:string,datenbank:string} */
function selfreg_handler_test_config(): array
{
    $database = getenv('ESTAB_DB_NAME') ?: '';
    if ($database !== ESTAB_SELFREG_HANDLER_DATABASE) {
        throw new RuntimeException(
            'Refusing self-registration handler test outside its isolated database'
        );
    }
    $password = getenv('ESTAB_DB_PASSWORD');
    if (!is_string($password) || $password === '') {
        $passwordFile = getenv('ESTAB_DB_PASSWORD_FILE');
        $password = is_string($passwordFile) && is_readable($passwordFile)
            ? trim((string) file_get_contents($passwordFile))
            : '';
    }
    if ($password === '') {
        throw new RuntimeException(
            'Self-registration handler database password is required'
        );
    }
    return [
        'server' => (getenv('ESTAB_DB_HOST') ?: 'db')
            . ':' . (getenv('ESTAB_DB_PORT') ?: '3306'),
        'user' => getenv('ESTAB_DB_USER') ?: 'root',
        'password' => $password,
        'datenbank' => $database,
    ];
}

function selfreg_handler_test_scalar(
    mysqli $connection,
    string $sql,
    string $types = '',
    mixed ...$parameters
): ?string {
    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('Could not prepare handler scalar query');
    }
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        if (!$statement->execute()) {
            throw new RuntimeException('Could not execute handler scalar query');
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        return is_array($row) ? (string) ($row[0] ?? '') : null;
    } finally {
        $statement->close();
    }
}

/** @return array<string,string>|null */
function selfreg_handler_test_row(
    mysqli $connection,
    string $sql,
    string $types = '',
    mixed ...$parameters
): ?array {
    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('Could not prepare handler row query');
    }
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        if (!$statement->execute()) {
            throw new RuntimeException('Could not execute handler row query');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? array_map('strval', $row) : null;
    } finally {
        $statement->close();
    }
}

/** @return list<string> */
function selfreg_handler_test_personal_tables(string $code): array
{
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1) {
        throw new InvalidArgumentException('Unsafe handler fixture code');
    }
    $base = 'usr_s2_' . $code;
    return [
        $base . '_read',
        $base . '_katego',
        $base . '_kategolink',
    ];
}

/** @return list<string> */
function selfreg_handler_test_function_tables(): array
{
    return [
        'usr__fkt_s2_erl',
        'usr__fkt_s2_katego',
        'usr__fkt_s2_kategolink',
    ];
}

/** @param list<string> $tables */
function selfreg_handler_test_table_count(
    mysqli $connection,
    array $tables
): int {
    $count = 0;
    foreach ($tables as $table) {
        $count += (int) (
            selfreg_handler_test_scalar(
                $connection,
                'SELECT COUNT(*) FROM information_schema.tables'
                    . ' WHERE table_schema = DATABASE() AND table_name = ?',
                's',
                $table
            ) ?? 0
        );
    }
    return $count;
}

/** @return array<string,int|string|null> */
function selfreg_handler_test_update_policy(
    mysqli $connection,
    string $mode,
    ?int $duration,
    string $actor
): array {
    $current = estab_self_registration_load($connection);
    $result = estab_self_registration_update(
        $connection,
        ESTAB_SELFREG_HANDLER_DATABASE,
        'nv_protokoll',
        $mode,
        $duration,
        (int) $current['revision'],
        $actor,
        '192.0.2.47'
    );
    $policy = $result['policy'] ?? null;
    if (!is_array($policy)) {
        throw new RuntimeException('Policy update returned no state');
    }
    return $policy;
}

/** Persist a short database-clock window used only by the expiry race. */
function selfreg_handler_test_set_short_window(
    mysqli $connection,
    int $seconds,
    string $actor
): array {
    if ($seconds < 2 || $seconds > 10) {
        throw new InvalidArgumentException('Unsafe expiry-race duration');
    }
    $mode = ESTAB_SELF_REGISTRATION_MODE_UNTIL;
    $statement = $connection->prepare(
        'UPDATE `nv_selbstregistrierung` SET `mode` = ?,'
            . ' `enabled_until_utc` = TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)),'
            . ' `revision` = `revision` + 1, `updated_at` = UTC_TIMESTAMP(6),'
            . ' `updated_by` = ? WHERE `singleton_id` = 1'
    );
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('Could not prepare short policy window');
    }
    try {
        $statement->bind_param('sis', $mode, $seconds, $actor);
        if (!$statement->execute() || $statement->affected_rows !== 1) {
            throw new RuntimeException('Could not persist short policy window');
        }
    } finally {
        $statement->close();
    }
    return estab_self_registration_load($connection);
}

/** Restore every persisted field without creating a cleanup audit record. */
function selfreg_handler_test_restore_policy(
    mysqli $connection,
    array $policy
): void {
    $mode = (string) $policy['mode'];
    $deadline = is_string($policy['enabled_until_utc'] ?? null)
        ? $policy['enabled_until_utc']
        : null;
    $revision = (int) $policy['revision'];
    $updatedAt = (string) $policy['updated_at'];
    $updatedBy = (string) $policy['updated_by'];
    $statement = $connection->prepare(
        'UPDATE `nv_selbstregistrierung` SET `mode` = ?,'
            . ' `enabled_until_utc` = ?, `revision` = ?, `updated_at` = ?,'
            . ' `updated_by` = ? WHERE `singleton_id` = 1'
    );
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('Could not prepare handler policy restore');
    }
    try {
        $statement->bind_param(
            'ssiss',
            $mode,
            $deadline,
            $revision,
            $updatedAt,
            $updatedBy
        );
        if (!$statement->execute() || $statement->affected_rows > 1) {
            throw new RuntimeException('Could not restore handler policy');
        }
    } finally {
        $statement->close();
    }
    $restored = estab_self_registration_load($connection);
    foreach (
        ['mode', 'enabled_until_utc', 'revision', 'updated_at', 'updated_by']
        as $field
    ) {
        if (($restored[$field] ?? null) !== ($policy[$field] ?? null)) {
            throw new RuntimeException('Handler policy restore was incomplete');
        }
    }
}

/** @param list<string> $tables */
function selfreg_handler_test_drop_tables(
    mysqli $connection,
    array $tables
): void {
    foreach (array_reverse($tables) as $table) {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $table) !== 1) {
            throw new InvalidArgumentException('Unsafe handler cleanup table');
        }
        if (!$connection->query('DROP TABLE IF EXISTS `' . $table . '`')) {
            throw new RuntimeException('Could not remove handler fixture table');
        }
    }
}

/** @return array<string,string> */
function selfreg_handler_test_case(
    string $code,
    string $name,
    string $password
): array {
    return [
        'code' => $code,
        'name' => $name,
        'function' => 'S2',
        'password' => $password,
    ];
}

function selfreg_handler_test_enter_controller(): string
{
    $originalDirectory = getcwd();
    if (
        !is_string($originalDirectory)
        || !chdir(dirname(__DIR__, 2) . '/4fach')
    ) {
        throw new RuntimeException('Could not enter handler directory');
    }
    try {
        if (!defined('debug')) {
            define('debug', false);
        }
        require_once dirname(__DIR__, 2) . '/4fach/db_operation.php';
        require_once dirname(__DIR__, 2) . '/4fach/data_hndl.php';
    } catch (Throwable $exception) {
        chdir($originalDirectory);
        throw $exception;
    }
    return $originalDirectory;
}

function selfreg_handler_test_leave_controller(string $directory): void
{
    if (!chdir($directory)) {
        throw new RuntimeException('Could not restore handler directory');
    }
}

/** Execute the productive legacy handler in a separate browser-like process. */
function selfreg_handler_test_worker(array $arguments): never
{
    if (count($arguments) !== 4) {
        throw new InvalidArgumentException('Invalid handler worker input');
    }
    [$code, $name, $function, $readyPath] = $arguments;
    if (
        preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1
        || $name === ''
        || $function !== 'S2'
        || ($readyPath !== '' && !str_starts_with(
            $readyPath,
            sys_get_temp_dir() . '/estab-selfreg-handler-'
        ))
    ) {
        throw new InvalidArgumentException('Unsafe handler worker input');
    }
    $controllerDirectory = selfreg_handler_test_enter_controller();
    try {
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER['REMOTE_ADDR'] = '192.0.2.47';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        ini_set('session.use_cookies', '0');
        session_id('esh' . bin2hex(random_bytes(16)));
        if (!session_start()) {
            throw new RuntimeException('Could not start handler worker session');
        }
        $_SESSION = ['menue' => 'LOGIN'];
        if (
            $readyPath !== ''
            && file_put_contents($readyPath, "ready\n", LOCK_EX) === false
        ) {
            throw new RuntimeException(
                'Could not publish handler worker readiness'
            );
        }
        $password = str_pad('Handler-Policy-2026!Aa1', 128, 'x');
        $request = [
            'login_flow' => 'new',
            'benutzer' => $name,
            'kuerzel' => $code,
            'funktion' => $function,
            'kennwort1' => $password,
            'kennwort2' => $password,
            '2teskennwort' => 'Yes',
        ];
        $loginError = '';
        $handlerResult = check_save_user($request, $loginError);
        $payload = [
            'handler_result' => $handlerResult,
            'login_error' => $loginError,
            'session_id' => session_id(),
            'session' => $_SESSION,
        ];
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        selfreg_handler_test_leave_controller($controllerDirectory);
    }
    echo json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
    ) . "\n";
    exit(0);
}

/**
 * @return array{process:resource,pipes:array<int,resource>,ready:?string}
 */
function selfreg_handler_test_start_worker(
    array $case,
    ?string $readyPath = null
): array {
    if (is_string($readyPath)) {
        @unlink($readyPath);
    }
    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            'auto_prepend_file=',
            __FILE__,
            '--worker',
            $case['code'],
            $case['name'],
            $case['function'],
            $readyPath ?? '',
        ],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start handler worker');
    }
    return ['process' => $process, 'pipes' => $pipes, 'ready' => $readyPath];
}

function selfreg_handler_test_wait_ready(array $worker): void
{
    $readyPath = $worker['ready'] ?? null;
    if (!is_string($readyPath) || $readyPath === '') {
        return;
    }
    $deadline = microtime(true) + 8.0;
    while (!is_file($readyPath) && microtime(true) < $deadline) {
        usleep(20_000);
    }
    if (!is_file($readyPath)) {
        throw new RuntimeException('Handler worker did not become ready');
    }
}

/** @return array<string,mixed> */
function selfreg_handler_test_finish_worker(array &$worker): array
{
    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    foreach ($worker['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $exitCode = proc_close($worker['process']);
    if (is_string($worker['ready'] ?? null)) {
        @unlink($worker['ready']);
    }
    $worker = [];
    if ($exitCode !== 0) {
        throw new RuntimeException(
            'Handler worker failed: ' . trim((string) $stderr)
        );
    }
    if (trim((string) $stderr) !== '') {
        throw new RuntimeException(
            'Handler worker wrote to stderr: ' . trim((string) $stderr)
        );
    }
    $decoded = json_decode(
        trim((string) $stdout),
        true,
        16,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($decoded)) {
        throw new RuntimeException('Handler worker returned no result');
    }
    return $decoded;
}

function selfreg_handler_test_abort_worker(array &$worker): void
{
    if ($worker === []) {
        return;
    }
    foreach ($worker['pipes'] ?? [] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($worker['process'] ?? null)) {
        proc_terminate($worker['process']);
        proc_close($worker['process']);
    }
    if (is_string($worker['ready'] ?? null)) {
        @unlink($worker['ready']);
    }
    $worker = [];
}

/** @return array<string,mixed> */
function selfreg_handler_test_run_worker(array $case): array
{
    $worker = selfreg_handler_test_start_worker($case);
    try {
        return selfreg_handler_test_finish_worker($worker);
    } finally {
        selfreg_handler_test_abort_worker($worker);
    }
}

function selfreg_handler_test_assert_success(
    mysqli $connection,
    array $case,
    array $result
): void {
    $code = $case['code'];
    $sessionId = $result['session_id'] ?? null;
    $session = $result['session'] ?? null;
    selfreg_handler_test_assert(
        ($result['handler_result'] ?? null) === false
            && ($result['login_error'] ?? null) === ''
            && is_string($sessionId)
            && estab_auth_session_id_is_valid($sessionId)
            && is_array($session)
            && estab_auth_session_identity($session) === [
                'benutzer' => $case['name'],
                'kuerzel' => $code,
                'funktion' => 'S2',
                'rolle' => 'Stab',
            ]
            && ($session['ROLLE'] ?? null) === 'Stab',
        'Persistent policy did not establish the expected handler session'
    );
    $account = selfreg_handler_test_row(
        $connection,
        'SELECT `benutzer`, `funktion`, `rolle`, `sid`, `ip`, `aktiv`,'
            . ' `password` FROM `nv_benutzer` WHERE `kuerzel` = ?',
        's',
        $code
    );
    selfreg_handler_test_assert(
        is_array($account)
            && ($account['benutzer'] ?? null) === $case['name']
            && ($account['funktion'] ?? null) === 'S2'
            && ($account['rolle'] ?? null) === 'Stab'
            && ($account['sid'] ?? null) === $sessionId
            && ($account['ip'] ?? null) === '192.0.2.47'
            && ($account['aktiv'] ?? null) === '1'
            && is_string($account['password'] ?? null)
            && !hash_equals($case['password'], $account['password'])
            && password_verify($case['password'], $account['password'])
            && (password_get_info($account['password'])['algoName'] ?? '')
                === 'argon2id',
        'Successful handler registration did not commit the exact account'
    );
    $auditJson = selfreg_handler_test_scalar(
        $connection,
        "SELECT `p_ereignis` FROM `nv_protokoll` WHERE `p_was` = 'Anmelden'"
            . ' AND `p_ereignis` LIKE ? ORDER BY `p_lfd` DESC LIMIT 1',
        's',
        '%"target":"' . $code . '"%'
    );
    $audit = is_string($auditJson)
        ? json_decode($auditJson, true, 8, JSON_THROW_ON_ERROR)
        : null;
    selfreg_handler_test_assert(
        is_array($audit)
            && ($audit['action'] ?? null) === 'self_registration'
            && ($audit['target'] ?? null) === $code
            && ($audit['function'] ?? null) === 'S2'
            && ($audit['role'] ?? null) === 'Stab'
            && ($audit['session_reference'] ?? null)
                === 'sha256:' . hash('sha256', $sessionId)
            && !str_contains((string) $auditJson, $sessionId)
            && !str_contains((string) $auditJson, $case['password']),
        'Successful handler registration audit is absent or unsafe'
    );
    $tables = array_merge(
        selfreg_handler_test_personal_tables($code),
        selfreg_handler_test_function_tables()
    );
    selfreg_handler_test_assert(
        selfreg_handler_test_table_count($connection, $tables) === 6,
        'Successful handler registration did not commit all dynamic tables'
    );
}

function selfreg_handler_test_assert_rejected(
    mysqli $connection,
    array $case,
    array $result,
    string $expectedError
): void {
    $code = $case['code'];
    $session = $result['session'] ?? null;
    selfreg_handler_test_assert(
        ($result['handler_result'] ?? null) === true
            && is_string($result['login_error'] ?? null)
            && str_contains($result['login_error'], $expectedError)
            && is_array($session)
            && estab_auth_session_identity($session) === null
            && $session === ['menue' => 'LOGIN'],
        'Rejected handler registration retained an identity or wrong error'
    );
    selfreg_handler_test_assert(
        selfreg_handler_test_scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_benutzer` WHERE `kuerzel` = ?',
            's',
            $code
        ) === '0',
        'Rejected handler registration created an account'
    );
    selfreg_handler_test_assert(
        selfreg_handler_test_scalar(
            $connection,
            "SELECT COUNT(*) FROM `nv_protokoll` WHERE `p_was` = 'Anmelden'"
                . ' AND `p_ereignis` LIKE ?',
            's',
            '%"target":"' . $code . '"%'
        ) === '0',
        'Rejected handler registration created a login audit'
    );
    selfreg_handler_test_assert(
        selfreg_handler_test_table_count(
            $connection,
            selfreg_handler_test_personal_tables($code)
        ) === 0,
        'Rejected handler registration created personal dynamic tables'
    );
}

function selfreg_handler_test_assert_pristine_case(
    mysqli $connection,
    array $case
): void {
    $code = $case['code'];
    selfreg_handler_test_assert(
        selfreg_handler_test_scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_benutzer` WHERE `kuerzel` = ?',
            's',
            $code
        ) === '0'
            && selfreg_handler_test_scalar(
                $connection,
                "SELECT COUNT(*) FROM `nv_protokoll`"
                    . " WHERE `p_was` = 'Anmelden'"
                    . ' AND `p_ereignis` LIKE ?',
                's',
                '%"target":"' . $code . '"%'
            ) === '0'
            && selfreg_handler_test_table_count(
                $connection,
                selfreg_handler_test_personal_tables($code)
            ) === 0,
        'Handler fixture did not start without account, audit and personal tables'
    );
}

if (($argv[1] ?? '') === '--worker') {
    try {
        selfreg_handler_test_config();
        selfreg_handler_test_worker(array_slice($argv, 2));
    } catch (Throwable $exception) {
        fwrite(
            STDERR,
            $exception::class . ': ' . $exception->getMessage() . "\n"
        );
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$config = selfreg_handler_test_config();
$controllerDirectory = selfreg_handler_test_enter_controller();
selfreg_handler_test_leave_controller($controllerDirectory);
$connection = estab_auth_connect($config);
$policyConnection = estab_auth_connect($config);
$originalPolicy = estab_self_registration_load($connection);
$functionTablesExisted = selfreg_handler_test_table_count(
    $connection,
    selfreg_handler_test_function_tables()
) > 0;
$auditFloor = (int) (
    selfreg_handler_test_scalar(
        $connection,
        'SELECT COALESCE(MAX(`p_lfd`), 0) FROM `nv_protokoll`'
    ) ?? 0
);
$token = substr(bin2hex(random_bytes(4)), 0, 4);
$password = str_pad('Handler-Policy-2026!Aa1', 128, 'x');
$cases = [
    'permanent' => selfreg_handler_test_case(
        'hp' . $token,
        'Handler dauerhaft ' . $token,
        $password
    ),
    'until' => selfreg_handler_test_case(
        'hu' . $token,
        'Handler befristet ' . $token,
        $password
    ),
    'expired_race' => selfreg_handler_test_case(
        'hr' . $token,
        'Handler Ablaufrisiko ' . $token,
        $password
    ),
    'disabled_race' => selfreg_handler_test_case(
        'hd' . $token,
        'Handler Deaktivierung ' . $token,
        $password
    ),
];
$actor = 'self-registration-handler-' . $token;
$temporaryFiles = [];

try {
    selfreg_handler_test_assert(
        ($originalPolicy['mode'] ?? null)
            === ESTAB_SELF_REGISTRATION_MODE_DISABLED,
        'Fresh isolated database did not start with self-registration disabled'
    );

    $permanentPolicy = selfreg_handler_test_update_policy(
        $connection,
        ESTAB_SELF_REGISTRATION_MODE_PERMANENT,
        null,
        $actor
    );
    selfreg_handler_test_assert(
        ($permanentPolicy['mode'] ?? null)
            === ESTAB_SELF_REGISTRATION_MODE_PERMANENT
            && estab_self_registration_is_allowed($permanentPolicy),
        'Could not establish persistent PERMANENT handler fixture'
    );
    selfreg_handler_test_assert(
        selfreg_handler_test_table_count(
            $connection,
            array_merge(
                selfreg_handler_test_personal_tables(
                    $cases['permanent']['code']
                ),
                selfreg_handler_test_function_tables()
            )
        ) === 0,
        'PERMANENT handler fixture did not start without dynamic tables'
    );
    $permanentResult = selfreg_handler_test_run_worker($cases['permanent']);
    selfreg_handler_test_assert_success(
        $connection,
        $cases['permanent'],
        $permanentResult
    );

    $untilPolicy = selfreg_handler_test_update_policy(
        $connection,
        ESTAB_SELF_REGISTRATION_MODE_UNTIL,
        15,
        $actor
    );
    selfreg_handler_test_assert(
        ($untilPolicy['mode'] ?? null) === ESTAB_SELF_REGISTRATION_MODE_UNTIL
            && estab_self_registration_is_allowed($untilPolicy),
        'Could not establish persistent future UNTIL handler fixture'
    );
    selfreg_handler_test_assert(
        selfreg_handler_test_table_count(
            $connection,
            selfreg_handler_test_personal_tables($cases['until']['code'])
        ) === 0,
        'UNTIL handler fixture inherited personal tables'
    );
    $untilResult = selfreg_handler_test_run_worker($cases['until']);
    selfreg_handler_test_assert_success(
        $connection,
        $cases['until'],
        $untilResult
    );

    // Let the early policy read succeed, then expire the database-clock
    // window while the productive handler waits on the per-account lock.
    $expiryPolicy = selfreg_handler_test_set_short_window(
        $connection,
        8,
        $actor
    );
    selfreg_handler_test_assert(
        estab_self_registration_is_allowed($expiryPolicy),
        'Short expiry-race policy was not initially active'
    );
    $expiryCase = $cases['expired_race'];
    selfreg_handler_test_assert_pristine_case($connection, $expiryCase);
    $accountLock = estab_login_account_lock_name(
        ESTAB_SELFREG_HANDLER_DATABASE,
        'nv_benutzer',
        $expiryCase['code']
    );
    $accountLockHeld = false;
    $expiryWorker = [];
    $expiryReady = sys_get_temp_dir() . '/estab-selfreg-handler-'
        . bin2hex(random_bytes(8)) . '.ready';
    $temporaryFiles[] = $expiryReady;
    try {
        estab_login_acquire_account_lock($connection, $accountLock);
        $accountLockHeld = true;
        $expiryWorker = selfreg_handler_test_start_worker(
            $expiryCase,
            $expiryReady
        );
        selfreg_handler_test_wait_ready($expiryWorker);
        $selfRegistrationLock = estab_self_registration_lock_name(
            ESTAB_SELFREG_HANDLER_DATABASE
        );
        $parentConnectionId = selfreg_handler_test_scalar(
            $connection,
            'SELECT CONNECTION_ID()'
        );
        $lockDeadline = microtime(true) + 6.0;
        $workerLockOwner = null;
        while (microtime(true) < $lockDeadline) {
            $workerLockOwner = selfreg_handler_test_scalar(
                $connection,
                'SELECT IS_USED_LOCK(?)',
                's',
                $selfRegistrationLock
            );
            if (
                is_string($workerLockOwner)
                && $workerLockOwner !== ''
                && $workerLockOwner !== $parentConnectionId
            ) {
                break;
            }
            usleep(20_000);
        }
        selfreg_handler_test_assert(
            is_string($workerLockOwner)
                && $workerLockOwner !== ''
                && $workerLockOwner !== $parentConnectionId,
            'Expiry worker never passed the early policy boundary'
        );
        $expiryDeadline = microtime(true) + 12.0;
        while (
            selfreg_handler_test_scalar(
                $connection,
                'SELECT (`enabled_until_utc` > UTC_TIMESTAMP(6))'
                    . ' FROM `nv_selbstregistrierung` WHERE `singleton_id` = 1'
            ) === '1'
        ) {
            if (microtime(true) >= $expiryDeadline) {
                throw new RuntimeException('Short handler policy did not expire');
            }
            usleep(25_000);
        }
        $expiryStatus = proc_get_status($expiryWorker['process']);
        selfreg_handler_test_assert(
            ($expiryStatus['running'] ?? false) === true
                && selfreg_handler_test_table_count(
                    $connection,
                    selfreg_handler_test_personal_tables($expiryCase['code'])
                ) === 0,
            'Expiry worker escaped the held account lock or created tables early'
        );
        estab_login_release_account_lock($connection, $accountLock);
        $accountLockHeld = false;
        $expiryResult = selfreg_handler_test_finish_worker($expiryWorker);
    } finally {
        if ($accountLockHeld) {
            try {
                estab_login_release_account_lock($connection, $accountLock);
            } catch (Throwable) {
            }
        }
        selfreg_handler_test_abort_worker($expiryWorker);
        @unlink($expiryReady);
    }
    selfreg_handler_test_assert_rejected(
        $connection,
        $expiryCase,
        $expiryResult,
        'inzwischen abgelaufen oder wurde beendet'
    );

    // Start another request while assignment evaluation is blocked. Persist a
    // disable through the real policy API before letting the handler proceed.
    selfreg_handler_test_update_policy(
        $connection,
        ESTAB_SELF_REGISTRATION_MODE_PERMANENT,
        null,
        $actor
    );
    $disabledCase = $cases['disabled_race'];
    selfreg_handler_test_assert_pristine_case($connection, $disabledCase);
    $assignmentLock = '';
    $assignmentLockHeld = false;
    $disabledWorker = [];
    $disabledReady = sys_get_temp_dir() . '/estab-selfreg-handler-'
        . bin2hex(random_bytes(8)) . '.ready';
    $temporaryFiles[] = $disabledReady;
    try {
        $assignmentLock = estab_assignment_acquire_policy_lock(
            $connection,
            ESTAB_SELFREG_HANDLER_DATABASE,
            'nv_empfmtx'
        );
        $assignmentLockHeld = true;
        $disabledWorker = selfreg_handler_test_start_worker(
            $disabledCase,
            $disabledReady
        );
        selfreg_handler_test_wait_ready($disabledWorker);
        usleep(200_000);
        $disabledStatus = proc_get_status($disabledWorker['process']);
        selfreg_handler_test_assert(
            ($disabledStatus['running'] ?? false) === true,
            'Disable-race worker did not wait on assignment policy'
        );
        $disabledPolicy = selfreg_handler_test_update_policy(
            $policyConnection,
            ESTAB_SELF_REGISTRATION_MODE_DISABLED,
            null,
            $actor
        );
        selfreg_handler_test_assert(
            ($disabledPolicy['mode'] ?? null)
                === ESTAB_SELF_REGISTRATION_MODE_DISABLED
                && !estab_self_registration_is_allowed($disabledPolicy),
            'Disable race did not persist DISABLED mode'
        );
        estab_assignment_release_policy_lock($connection, $assignmentLock);
        $assignmentLockHeld = false;
        $disabledResult = selfreg_handler_test_finish_worker($disabledWorker);
    } finally {
        if ($assignmentLockHeld) {
            try {
                estab_assignment_release_policy_lock(
                    $connection,
                    $assignmentLock
                );
            } catch (Throwable) {
            }
        }
        selfreg_handler_test_abort_worker($disabledWorker);
        @unlink($disabledReady);
    }
    selfreg_handler_test_assert_rejected(
        $connection,
        $disabledCase,
        $disabledResult,
        'derzeit nicht selbst angelegt werden'
    );
} finally {
    foreach ($temporaryFiles as $temporaryFile) {
        @unlink($temporaryFile);
    }
    try {
        selfreg_handler_test_restore_policy($connection, $originalPolicy);
        foreach ($cases as $case) {
            $code = $case['code'];
            $statement = $connection->prepare(
                'DELETE FROM `nv_benutzer` WHERE `kuerzel` = ?'
            );
            if ($statement instanceof mysqli_stmt) {
                $statement->bind_param('s', $code);
                $statement->execute();
                $statement->close();
            }
            selfreg_handler_test_drop_tables(
                $connection,
                selfreg_handler_test_personal_tables($code)
            );
        }
        if (!$functionTablesExisted) {
            selfreg_handler_test_drop_tables(
                $connection,
                selfreg_handler_test_function_tables()
            );
        }
        $auditPattern = '%' . $actor . '%';
        $statement = $connection->prepare(
            'DELETE FROM `nv_protokoll` WHERE `p_lfd` > ?'
                . ' AND (`p_ereignis` LIKE ? OR `p_was` = \'Anmelden\')'
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('is', $auditFloor, $auditPattern);
            $statement->execute();
            $statement->close();
        }
    } finally {
        estab_auth_close($policyConnection);
        estab_auth_close($connection);
    }
}

printf(
    "Self-registration handler MariaDB: OK (%d assertions)\n",
    $assertions
);
