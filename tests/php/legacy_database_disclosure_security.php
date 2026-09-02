<?php

declare(strict_types=1);

/**
 * The legacy database layer must not tell the browser how it failed.
 *
 * Five die() calls printed the failing SQL statement together with the MySQL
 * error text and number straight into the response, and that path is reachable
 * in production. The same layer also downgraded the connection charset with
 * `SET NAMES utf8` right after the compatibility shim had set utf8mb4, which
 * silently truncated every character outside the basic plane.
 */

$root = dirname(__DIR__, 2);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$legacyFiles = [
    '4fach/db_operation.php',
    '4fach/mainindex.php',
    'stabetb/etb.php',
    'fmtbb/tbb.php',
];
$sources = [];
foreach ($legacyFiles as $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read ' . $relative);
    }
    $sources[$relative] = $contents;
}

// The shim owns the charset. A manual SET NAMES can only make it worse.
foreach ($sources as $relative => $source) {
    $assert(
        stripos($source, 'SET NAMES') === false,
        $relative . ' sets the connection charset by hand and can therefore'
            . ' undo the utf8mb4 the compatibility layer established'
    );
}
$shim = file_get_contents($root . '/app/legacy_mysql.php');
if (!is_string($shim)) {
    throw new RuntimeException('Could not read app/legacy_mysql.php');
}
$assert(
    str_contains($shim, "set_charset('utf8mb4')"),
    'The compatibility layer no longer establishes utf8mb4 on connect'
);

// No response may carry the statement or the driver diagnostics.
foreach ($sources as $relative => $source) {
    $assert(
        preg_match('~\bdie\s*\(.*mysql_err~s', $source) !== 1,
        $relative . ' still ends the request with the driver error text'
    );
    $assert(
        preg_match('~\becho\s+[^;]*mysql_err~', $source) !== 1,
        $relative . ' still echoes the driver error text'
    );
    $assert(
        preg_match('~\bdie\s*\([^;]*\$query~', $source) !== 1,
        $relative . ' still ends the request with the failing statement'
    );
}

// Behaviour: the replacement logs the details and answers with neither.
require_once $root . '/app/legacy_mysql.php';
$assert(
    function_exists('estab_legacy_database_failure'),
    'The shared failure handler does not exist'
);

$logFile = tempnam(sys_get_temp_dir(), 'estab-legacy-db-log-');
if (!is_string($logFile)) {
    throw new RuntimeException('Could not create the log fixture');
}
$previousLog = ini_get('error_log');
$previousLogErrors = ini_get('log_errors');
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$statement = 'SELECT `x03_sperruser` FROM `nv_nachrichten` WHERE `00_lfd` = 42';
$response = '';
try {
    // The handler ends the request, so its behaviour is observed in a child
    // interpreter that writes into this process' log target.
    $script = <<<'CHILD'
require_once %s;
ini_set('error_log', %s);
ini_set('log_errors', '1');
estab_legacy_database_failure('unit_probe', %s);
echo 'REQUEST-CONTINUED';
CHILD;
    $child = sprintf(
        $script,
        var_export($root . '/app/legacy_mysql.php', true),
        var_export($logFile, true),
        var_export($statement, true)
    );
    $output = [];
    $status = 0;
    exec(
        escapeshellarg(PHP_BINARY !== '' ? PHP_BINARY : 'php')
            . ' -r ' . escapeshellarg($child) . ' 2>/dev/null',
        $output,
        $status
    );
    $response = implode("\n", $output);
    $assert(
        !str_contains($response, 'REQUEST-CONTINUED'),
        'The failure handler let the request continue instead of ending it'
    );
} finally {
    ini_set('error_log', is_string($previousLog) ? $previousLog : '');
    ini_set('log_errors', is_string($previousLogErrors) ? $previousLogErrors : '1');
}

$assert(
    $response !== '',
    'The failure handler answered with nothing at all'
);
$assert(
    !str_contains($response, 'nv_nachrichten')
        && !str_contains($response, 'x03_sperruser')
        && !str_contains($response, 'SELECT'),
    'The failure handler still shows the failing statement: ' . $response
);
$assert(
    str_contains($response, 'Serverprotokoll'),
    'The failure handler does not point the operator at the server log: '
        . $response
);

$logged = file_get_contents($logFile);
@unlink($logFile);
$assert(
    is_string($logged) && str_contains($logged, 'unit_probe')
        && str_contains($logged, 'nv_nachrichten'),
    'The failing statement was not written to the server log, so the'
        . ' diagnosis is lost instead of merely hidden'
);

printf(
    "legacy database disclosure: OK (%d assertions)\n",
    $assertions
);
