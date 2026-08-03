<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../4fach/db_operation.php';

define('ESTAB_DYNAMIC_SCHEMA_LOCK_TIMEOUT_SECONDS', 0);
define('ESTAB_LOGIN_LOCK_TIMEOUT_SECONDS', 0);
define('ESTAB_USER_ADMIN_ACCOUNT_LOCK_TIMEOUT', 0);

require_once __DIR__ . '/../../app/user_admin.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_query(string $sql, mysqli $link): mysqli_result|bool
{
    $result = mysql_query($sql, $link);
    if ($result === false) {
        throw new RuntimeException(mysql_error($link) . "\nSQL: " . $sql);
    }
    return $result;
}

function test_scalar(string $sql, mysqli $link): string|null
{
    $result = test_query($sql, $link);
    test_assert($result instanceof mysqli_result, 'Expected a result set');
    $row = mysql_fetch_row($result);
    mysql_free_result($result);
    return $row === null || $row === false ? null : (string) $row[0];
}

function test_connect(
    string $server,
    string $database,
    string $username,
    string $password
): mysqli {
    $link = mysql_connect($server, $username, $password);
    test_assert($link instanceof mysqli, 'Could not connect to MariaDB: ' . mysql_error());
    test_assert(mysql_select_db($database, $link), 'Could not select test database');
    return $link;
}

function drop_fixture_tables(
    string $server,
    string $database,
    string $username,
    string $password,
    array $tables
): void {
    $link = mysql_connect($server, $username, $password);
    if (!$link instanceof mysqli || !mysql_select_db($database, $link)) {
        return;
    }
    foreach (array_reverse($tables) as $table) {
        mysql_query('DROP TABLE IF EXISTS `' . $table . '`', $link);
    }
    mysql_close($link);
}

$host = getenv('ESTAB_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('ESTAB_TEST_DB_PORT') ?: 3306);
$database = getenv('ESTAB_TEST_DB_NAME') ?: 'estab_dynamic_test';
$username = getenv('ESTAB_TEST_DB_USER') ?: 'root';
$password = getenv('ESTAB_TEST_DB_PASSWORD') ?: 'estab-test';
$server = $host . ':' . $port;
$strictMode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
$userBase = 'usr_s2_test';
$functionBase = 'usr__fkt_s2';
$tables = [
    $userBase . '_read',
    $functionBase . '_erl',
    $functionBase . '_katego',
    $functionBase . '_kategolink',
    $userBase . '_katego',
    $userBase . '_kategolink',
];

test_assert((bool) preg_match('/^[A-Za-z0-9_]+$/D', $database), 'Unsafe database name');
drop_fixture_tables($server, $database, $username, $password, $tables);
register_shutdown_function(
    static fn () => drop_fixture_tables(
        $server,
        $database,
        $username,
        $password,
        $tables
    )
);

// Deliberately reproduce all six 0.9.x tables and representative data.
$legacy = test_connect($server, $database, $username, $password);
$legacyMode = test_scalar('SELECT @@SESSION.sql_mode', $legacy) ?? '';
test_query("SET SESSION sql_mode = ''", $legacy);
test_query(
    "CREATE TABLE `{$userBase}_read` (
       `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
       `zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       `nachnum` BIGINT NOT NULL,
       `gelesen` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
       PRIMARY KEY (`lfd`),
       KEY `nachnum` (`nachnum`)
     ) ENGINE=MyISAM DEFAULT CHARSET=latin1",
    $legacy
);
test_query(
    "CREATE TABLE `{$functionBase}_erl` (
       `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
       `zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       `nachnum` BIGINT NOT NULL,
       `erledigt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
       PRIMARY KEY (`lfd`),
       KEY `nachnum` (`nachnum`)
     ) ENGINE=MyISAM DEFAULT CHARSET=latin1",
    $legacy
);
foreach ([$functionBase, $userBase] as $base) {
    test_query(
        "CREATE TABLE `{$base}_katego` (
           `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
           `kategorie` VARCHAR(10) NOT NULL,
           `beschreibung` VARCHAR(254) NULL,
           PRIMARY KEY (`lfd`)
         ) ENGINE=MyISAM DEFAULT CHARSET=latin1",
        $legacy
    );
    test_query(
        "CREATE TABLE `{$base}_kategolink` (
           `msg` BIGINT NOT NULL,
           `katego` BIGINT NOT NULL
         ) ENGINE=MyISAM DEFAULT CHARSET=latin1",
        $legacy
    );
}
test_query(
    "INSERT INTO `{$userBase}_read` (`nachnum`, `gelesen`) VALUES
       (501, '0000-00-00 00:00:00'), (501, '2018-07-02 05:51:26')",
    $legacy
);
test_query(
    "INSERT INTO `{$functionBase}_erl` (`nachnum`, `erledigt`) VALUES
       (501, '0000-00-00 00:00:00'), (501, '2018-07-02 06:00:00')",
    $legacy
);
test_query(
    "INSERT INTO `{$functionBase}_katego` (`kategorie`, `beschreibung`) VALUES
       ('LAGE', 'Größe der Lage'), ('EINSATZ', 'Einsatz')",
    $legacy
);
test_query(
    "INSERT INTO `{$userBase}_katego` (`kategorie`, `beschreibung`) VALUES
       ('PRIVAT', 'Persönlich'), ('OFFEN', 'Offen')",
    $legacy
);
foreach ([$functionBase, $userBase] as $base) {
    test_query(
        "INSERT INTO `{$base}_kategolink` (`msg`, `katego`) VALUES
           (501, 1), (501, 1), (501, 2), (502, 1)",
        $legacy
    );
}
$escapedLegacyMode = mysql_real_escape_string($legacyMode, $legacy);
test_query("SET SESSION sql_mode = '" . $escapedLegacyMode . "'", $legacy);
mysql_close($legacy);

$access = new db_access($server, $database, '', $username, $password);
$access->create_user_table($userBase, $functionBase);
$access->create_user_table($userBase, $functionBase);

$unsafeRejected = false;
try {
    $access->create_user_table('usr_bad` DROP TABLE x', $functionBase);
} catch (InvalidArgumentException) {
    $unsafeRejected = true;
}
test_assert($unsafeRejected, 'Unsafe dynamic identifier was accepted');

$oversizedRejected = false;
try {
    $access->create_user_table(str_repeat('a', 64), $functionBase);
} catch (InvalidArgumentException) {
    $oversizedRejected = true;
}
test_assert($oversizedRejected, 'Oversized dynamic identifier was accepted');

$methodLink = EstabLegacyMysql::link();
test_assert(
    $methodLink === false,
    'create_user_table left its private database connection open'
);
$modeVerification = test_connect($server, $database, $username, $password);
$restoredMode = test_scalar('SELECT @@SESSION.sql_mode', $modeVerification) ?? '';
foreach (explode(',', $strictMode) as $requiredMode) {
    test_assert(str_contains($restoredMode, $requiredMode), 'SQL mode not restored: ' . $requiredMode);
}
mysql_close($modeVerification);

$link = test_connect($server, $database, $username, $password);
$escapedDatabase = mysql_real_escape_string($database, $link);
$quotedTables = implode(',', array_map(
    static fn (string $table): string => "'" . $table . "'",
    $tables
));

$metadata = test_query(
    "SELECT table_name, engine, table_collation
       FROM information_schema.tables
      WHERE table_schema = '{$escapedDatabase}'
        AND table_name IN ({$quotedTables})",
    $link
);
test_assert($metadata instanceof mysqli_result, 'Expected table metadata');
$seen = [];
while ($row = mysql_fetch_assoc($metadata)) {
    $seen[$row['table_name']] = true;
    test_assert($row['engine'] === 'InnoDB', $row['table_name'] . ' is not InnoDB');
    test_assert(str_starts_with($row['table_collation'], 'utf8mb4_'), $row['table_name'] . ' is not utf8mb4');
}
mysql_free_result($metadata);
test_assert(count($seen) === 6, 'Not all six dynamic tables exist');

test_assert(
    test_scalar(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = '{$escapedDatabase}'
            AND table_name IN ({$quotedTables})
            AND LOWER(COALESCE(column_default, '')) LIKE '%0000-00-00%'",
        $link
    ) === '0',
    'A zero-date default remains'
);
test_assert(
    test_scalar("SELECT COUNT(*) FROM `{$userBase}_read`", $link) === '2',
    'Read rows were lost'
);
test_assert(
    test_scalar("SELECT COUNT(*) FROM `{$functionBase}_erl`", $link) === '2',
    'Done rows were lost'
);
test_assert(
    test_scalar("SELECT COUNT(*) FROM `{$userBase}_read` WHERE `gelesen` IS NULL", $link) === '1',
    'Legacy read zero date was not converted to NULL'
);
test_assert(
    test_scalar("SELECT COUNT(*) FROM `{$functionBase}_erl` WHERE `erledigt` IS NULL", $link) === '1',
    'Legacy done zero date was not converted to NULL'
);
test_assert(
    test_scalar(
        "SELECT DATE_FORMAT(`gelesen`, '%Y-%m-%d %H:%i:%s')
           FROM `{$userBase}_read` WHERE `gelesen` IS NOT NULL",
        $link
    ) === '2018-07-02 05:51:26',
    'Valid legacy timestamp changed'
);
test_assert(
    test_scalar(
        "SELECT `beschreibung` FROM `{$functionBase}_katego` WHERE `kategorie` = 'LAGE'",
        $link
    ) === 'Größe der Lage',
    'latin1 category text was not preserved'
);

foreach ([$functionBase, $userBase] as $base) {
    test_assert(
        test_scalar("SELECT COUNT(*) FROM `{$base}_kategolink`", $link) === '4',
        $base . ' link rows were lost'
    );
    test_assert(
        test_scalar(
            "SELECT COUNT(*) FROM `{$base}_kategolink` WHERE `msg` = 501 AND `katego` = 1",
            $link
        ) === '2',
        $base . ' duplicate legacy assignments were lost'
    );
    test_assert(
        test_scalar("SELECT COUNT(DISTINCT `lfd`) FROM `{$base}_kategolink`", $link) === '4',
        $base . ' link primary keys are missing'
    );
}

$expectedIndexes = [
    $userBase . '_read' => ['PRIMARY' => ['lfd', '0'], 'idx_nachnum' => ['nachnum', '1']],
    $functionBase . '_erl' => ['PRIMARY' => ['lfd', '0'], 'idx_nachnum' => ['nachnum', '1']],
    $functionBase . '_katego' => ['PRIMARY' => ['lfd', '0'], 'idx_kategorie' => ['kategorie', '1']],
    $userBase . '_katego' => ['PRIMARY' => ['lfd', '0'], 'idx_kategorie' => ['kategorie', '1']],
    $functionBase . '_kategolink' => [
        'PRIMARY' => ['lfd', '0'],
        'idx_msg_katego' => ['msg,katego', '1'],
        'idx_katego_msg' => ['katego,msg', '1'],
    ],
    $userBase . '_kategolink' => [
        'PRIMARY' => ['lfd', '0'],
        'idx_msg_katego' => ['msg,katego', '1'],
        'idx_katego_msg' => ['katego,msg', '1'],
    ],
];
$indexes = test_query(
    "SELECT table_name, index_name, MIN(non_unique) AS non_unique,
            GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS indexed_columns
       FROM information_schema.statistics
      WHERE table_schema = '{$escapedDatabase}'
        AND table_name IN ({$quotedTables})
      GROUP BY table_name, index_name",
    $link
);
test_assert($indexes instanceof mysqli_result, 'Expected index metadata');
while ($row = mysql_fetch_assoc($indexes)) {
    $table = $row['table_name'];
    $index = $row['index_name'];
    if (!isset($expectedIndexes[$table][$index])) {
        continue;
    }
    test_assert($expectedIndexes[$table][$index][0] === $row['indexed_columns'], $table . '.' . $index . ' columns differ');
    test_assert($expectedIndexes[$table][$index][1] === $row['non_unique'], $table . '.' . $index . ' uniqueness differs');
    unset($expectedIndexes[$table][$index]);
    if ($expectedIndexes[$table] === []) {
        unset($expectedIndexes[$table]);
    }
}
mysql_free_result($indexes);
test_assert($expectedIndexes === [], 'Required indexes are missing');

test_query("INSERT INTO `{$userBase}_read` (`nachnum`) VALUES (503)", $link);
test_assert(
    test_scalar("SELECT COUNT(*) FROM `{$userBase}_read` WHERE `nachnum` = 503 AND `gelesen` IS NULL", $link) === '1',
    'Strict-mode insert with nullable date failed'
);
test_query(
    "INSERT INTO `{$userBase}_kategolink` (`msg`, `katego`) VALUES (503, 1), (503, 2)",
    $link
);
test_assert(
    test_scalar("SELECT COUNT(*) FROM `{$userBase}_kategolink` WHERE `msg` = 503", $link) === '2',
    'Multiple category assignments no longer work'
);

// An administratively provisioned account must remain inactive until all six
// dynamic tables are ready. Exercise both account-level and shared-schema
// contention before proving that the exact same existing-account login can be
// retried.
$registrationCode = 'atm001';
$registrationName = 'Atomic Registration';
$registrationUserBase = 'usr_s2_' . $registrationCode;
$registrationTables = [
    $registrationUserBase . '_read',
    $functionBase . '_erl',
    $functionBase . '_katego',
    $functionBase . '_kategolink',
    $registrationUserBase . '_katego',
    $registrationUserBase . '_kategolink',
];
$registrationUserTables = [
    $registrationUserBase . '_read',
    $registrationUserBase . '_katego',
    $registrationUserBase . '_kategolink',
];
$extraGrantUserBase = 'usr_s1_' . $registrationCode;
$extraGrantUserTables = [
    $extraGrantUserBase . '_read',
    $extraGrantUserBase . '_katego',
    $extraGrantUserBase . '_kategolink',
];
$registrationUserTables = array_merge(
    $registrationUserTables,
    $extraGrantUserTables
);
$quotedRegistrationTables = implode(',', array_map(
    static fn (string $table): string => "'" . $table . "'",
    $registrationTables
));
$quotedRegistrationUserTables = implode(',', array_map(
    static fn (string $table): string => "'" . $table . "'",
    $registrationUserTables
));

test_query(
    "DELETE FROM `nv_benutzer` WHERE `kuerzel` = '{$registrationCode}'",
    $link
);
test_query(
    "DELETE FROM `nv_protokoll`"
    . " WHERE `p_ereignis` LIKE '%\"target\":\"{$registrationCode}\"%'",
    $link
);
test_query(
    "DELETE FROM `nv_protokoll`"
    . " WHERE `p_was` = 'Benutzerverwaltung'"
    . " AND `p_ereignis` LIKE '%\"target\":\"{$registrationCode}\"%'",
    $link
);
foreach ($registrationUserTables as $registrationTable) {
    test_query("DROP TABLE IF EXISTS `{$registrationTable}`", $link);
}

$provisionConfig = [
    'server' => $server,
    'user' => $username,
    'password' => $password,
    'datenbank' => $database,
];
$provisionConnection = estab_auth_connect($provisionConfig);
try {
    $provisioned = estab_user_admin_create_account(
        $provisionConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $registrationName,
        $registrationCode,
        'S2',
        'atomic-registration-password',
        'atomic-registration-password',
        'nv_empfmtx',
        'dynamic-table-integration',
        '127.0.0.1'
    );
} finally {
    estab_auth_close($provisionConnection);
}
test_assert(
    ($provisioned['active_session_revoked'] ?? null) === false
        && test_scalar(
            "SELECT COUNT(*) FROM `nv_benutzer`"
            . " WHERE `kuerzel` = '{$registrationCode}'"
            . " AND `funktion` = 'S2' AND `rolle` = 'Stab'"
            . " AND `aktiv` = 0 AND `sid` = ''",
            $link
        ) === '1',
    'Administrative provisioning did not create one inactive assigned account'
);

$originalDirectory = getcwd();
test_assert(is_string($originalDirectory), 'Could not read current directory');
test_assert(chdir(__DIR__ . '/../../4fach'), 'Could not enter application directory');
if (!defined('debug')) {
    define('debug', false);
}
require_once __DIR__ . '/../../4fach/data_hndl.php';
$conf_empf = [
    1 => ['fkt' => 'S2', 'rolle' => 'Stab'],
];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
test_assert(session_start(), 'Could not start registration test session');
$_SESSION = ['menue' => 'LOGIN'];

$registrationRequest = [
    'login_flow' => 'existing',
    'benutzer' => $registrationName,
    'kuerzel' => $registrationCode,
    'funktion' => 'S2',
    'kennwort1' => 'atomic-registration-password',
];
$accountLockName = estab_login_account_lock_name(
    $database,
    'nv_benutzer',
    $registrationCode
);
$escapedAccountLockName = mysql_real_escape_string($accountLockName, $link);
test_assert(
    test_scalar("SELECT GET_LOCK('{$escapedAccountLockName}', 0)", $link) === '1',
    'Could not hold competing account lock'
);
$loginError = '';
test_assert(
    check_save_user($registrationRequest, $loginError) === true,
    'Existing-account login succeeded while the account lock was held'
);
test_assert(
    str_contains($loginError, 'technisch nicht abgeschlossen'),
    'Account-lock failure did not use the technical login error'
);
test_assert(
    test_scalar(
        "SELECT COUNT(*) FROM `nv_benutzer`"
        . " WHERE `kuerzel` = '{$registrationCode}'"
        . " AND `aktiv` = 0 AND `sid` = ''",
        $link
    ) === '1',
    'Account-lock failure activated or removed the provisioned account'
);
test_assert(
    estab_auth_session_identity($_SESSION) === null,
    'Account-lock failure authenticated a session'
);
test_assert(
    test_scalar(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = '{$escapedDatabase}'
            AND table_name IN ({$quotedRegistrationUserTables})",
        $link
    ) === '0',
    'Account-lock failure created user tables'
);
test_assert(
    test_scalar("SELECT RELEASE_LOCK('{$escapedAccountLockName}')", $link) === '1',
    'Could not release competing account lock'
);

$schemaLockName = estab_dynamic_schema_lock_name($database, $functionBase);
$escapedSchemaLockName = mysql_real_escape_string($schemaLockName, $link);
test_assert(
    test_scalar("SELECT GET_LOCK('{$escapedSchemaLockName}', 0)", $link) === '1',
    'Could not hold competing schema lock'
);
$_SESSION = ['menue' => 'LOGIN'];
$loginError = '';
test_assert(
    check_save_user($registrationRequest, $loginError) === true,
    'Existing-account login succeeded while the shared schema lock was held'
);
test_assert(
    test_scalar(
        "SELECT COUNT(*) FROM `nv_benutzer`"
        . " WHERE `kuerzel` = '{$registrationCode}'"
        . " AND `aktiv` = 0 AND `sid` = ''",
        $link
    ) === '1',
    'Schema-lock failure activated or removed the provisioned account'
);
test_assert(
    estab_auth_session_identity($_SESSION) === null,
    'Schema-lock failure authenticated a session'
);
test_assert(
    test_scalar(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = '{$escapedDatabase}'
            AND table_name IN ({$quotedRegistrationUserTables})",
        $link
    ) === '0',
    'Schema-lock failure left partial user tables'
);
test_assert(
    test_scalar("SELECT RELEASE_LOCK('{$escapedSchemaLockName}')", $link) === '1',
    'Could not release competing schema lock'
);

$_SESSION = ['menue' => 'LOGIN'];
$validationDatabase = [
    'server' => $server,
    'user' => $username,
    'password' => $password,
    'datenbank' => $database,
];
test_assert(
    estab_auth_current_session_identity(
        $_SESSION,
        $validationDatabase,
        'nv_benutzer',
        session_id(),
        true
    ) === null,
    'Anonymous pre-login state unexpectedly authenticated'
);
$loginError = '';
test_assert(
    check_save_user($registrationRequest, $loginError) === false,
    'Existing-account login retry did not succeed: ' . $loginError
);
test_assert(
    test_scalar(
        "SELECT COUNT(*) FROM `nv_benutzer`
          WHERE `kuerzel` = '{$registrationCode}' AND `aktiv` = 1",
        $link
    ) === '1',
    'Successful login did not activate exactly one provisioned account'
);
test_assert(
    estab_auth_session_identity($_SESSION) === [
        'benutzer' => $registrationName,
        'kuerzel' => $registrationCode,
        'funktion' => 'S2',
        'rolle' => 'Stab',
    ],
    'Successful existing-account login did not establish the committed identity'
);
test_assert(
    estab_auth_current_session_identity(
        $_SESSION,
        $validationDatabase,
        'nv_benutzer',
        session_id(),
        true
    ) !== null,
    'Anonymous request state was cached across a successful login'
);
$firstAuthenticatedSession = $_SESSION;
$firstAuthenticatedSessionId = session_id();
test_assert(
    estab_auth_session_id_is_valid($firstAuthenticatedSessionId),
    'Successful existing-account login produced an invalid session ID'
);
test_assert(
    test_scalar(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = '{$escapedDatabase}'
            AND table_name IN ({$quotedRegistrationTables})",
        $link
    ) === '6',
    'Successful existing-account login did not leave all six dynamic tables ready'
);
$loginAuditResult = test_query(
    "SELECT `p_ereignis` FROM `nv_protokoll`
      WHERE `p_was` = 'Anmelden'
        AND `p_ereignis` LIKE '%\"target\":\"{$registrationCode}\"%'
      ORDER BY `p_lfd` DESC LIMIT 1",
    $link
);
test_assert($loginAuditResult instanceof mysqli_result, 'Expected login audit');
$loginAuditRow = mysql_fetch_assoc($loginAuditResult);
mysql_free_result($loginAuditResult);
$loginAuditJson = is_array($loginAuditRow)
    ? (string) ($loginAuditRow['p_ereignis'] ?? '')
    : '';
$loginAudit = json_decode(
    $loginAuditJson,
    true,
    8,
    JSON_THROW_ON_ERROR
);
test_assert(
    ($loginAudit['action'] ?? null) === 'existing_login'
        && ($loginAudit['target'] ?? null) === $registrationCode
        && ($loginAudit['session_reference'] ?? null)
            === 'sha256:' . hash('sha256', $firstAuthenticatedSessionId)
        && !str_contains($loginAuditJson, $firstAuthenticatedSessionId),
    'Existing-account audit is missing, uncommitted, or leaks its raw SID'
);

// Model another browser signing in to the same active account. The newer SID
// must supersede the first browser without allowing that stale browser to
// deactivate or continue using the account.
session_write_close();
session_id('estabtest' . bin2hex(random_bytes(8)));
test_assert(session_start(), 'Could not start second browser session');
$_SESSION = ['menue' => 'LOGIN'];
$secondBrowserPreLoginSessionId = session_id();
$existingLoginRequest = [
    'login_flow' => 'existing',
    'benutzer' => $registrationName,
    'kuerzel' => $registrationCode,
    'funktion' => 'S2',
    'kennwort1' => 'atomic-registration-password',
];
$loginError = '';
test_assert(
    check_save_user($existingLoginRequest, $loginError) === false,
    'Second browser login did not succeed: ' . $loginError
);
$secondAuthenticatedSession = $_SESSION;
$secondAuthenticatedSessionId = session_id();
test_assert(
    $secondAuthenticatedSessionId !== $firstAuthenticatedSessionId
        && $secondAuthenticatedSessionId !== $secondBrowserPreLoginSessionId,
    'Second browser login did not rotate to a distinct session ID'
);
test_assert(
    test_scalar(
        "SELECT `sid` FROM `nv_benutzer`
          WHERE `kuerzel` = '{$registrationCode}' AND `aktiv` = 1",
        $link
    ) === $secondAuthenticatedSessionId,
    'Authoritative account row did not retain only the newer SID'
);
$refreshAuditResult = test_query(
    "SELECT `p_ereignis` FROM `nv_protokoll`
      WHERE `p_was` = 'Sessiondaten neu setzen'
        AND `p_ereignis` LIKE '%\"target\":\"{$registrationCode}\"%'
      ORDER BY `p_lfd` DESC LIMIT 1",
    $link
);
test_assert(
    $refreshAuditResult instanceof mysqli_result,
    'Expected session-refresh audit'
);
$refreshAuditRow = mysql_fetch_assoc($refreshAuditResult);
mysql_free_result($refreshAuditResult);
$refreshAuditJson = is_array($refreshAuditRow)
    ? (string) ($refreshAuditRow['p_ereignis'] ?? '')
    : '';
$refreshAudit = json_decode(
    $refreshAuditJson,
    true,
    8,
    JSON_THROW_ON_ERROR
);
test_assert(
    ($refreshAudit['action'] ?? null) === 'session_refresh'
        && ($refreshAudit['target'] ?? null) === $registrationCode
        && ($refreshAudit['session_reference'] ?? null)
            === 'sha256:' . hash('sha256', $secondAuthenticatedSessionId)
        && !str_contains($refreshAuditJson, $secondAuthenticatedSessionId),
    'Session-refresh audit is missing or leaks its raw SID'
);

$staleBrowserSession = $firstAuthenticatedSession;
test_assert(
    estab_auth_current_session_identity(
        $staleBrowserSession,
        $validationDatabase,
        'nv_benutzer',
        $firstAuthenticatedSessionId
    ) === null,
    'First browser remained authorized after the second login'
);
test_assert(
    $staleBrowserSession === ['menue' => 'LOGIN'],
    'Revoked browser retained local workflow state'
);

$currentBrowserSession = $secondAuthenticatedSession;
$currentBrowserIdentity = estab_auth_current_session_identity(
    $currentBrowserSession,
    $validationDatabase,
    'nv_benutzer',
    $secondAuthenticatedSessionId
);
test_assert(
    is_array($currentBrowserIdentity)
        && ($currentBrowserIdentity['benutzer'] ?? null)
            === $registrationName
        && ($currentBrowserIdentity['kuerzel'] ?? null)
            === $registrationCode
        && ($currentBrowserIdentity['funktion'] ?? null) === 'S2'
        && ($currentBrowserIdentity['rolle'] ?? null) === 'Stab'
        && ($currentBrowserIdentity['estab_permission_mode'] ?? null)
            === ESTAB_PERMISSION_MODE_LOOSE
        && ($currentBrowserIdentity['estab_additional_functions'] ?? null)
            === [],
    'Current browser was rejected by authoritative SID validation'
);
test_assert(
    estab_auth_mark_logged_out(
        $link,
        'nv_benutzer',
        $registrationCode,
        $firstAuthenticatedSessionId
    ) === false
        && test_scalar(
            "SELECT COUNT(*) FROM `nv_benutzer`
              WHERE `kuerzel` = '{$registrationCode}'
                AND `sid` = '"
                . mysql_real_escape_string(
                    $secondAuthenticatedSessionId,
                    $link
                )
                . "' AND `aktiv` = 1",
            $link
        ) === '1',
    'Stale-browser logout deactivated the newer account session'
);

// Granting a LOOSE extra function must provision its legacy workspace before
// committing authority. A schema-lock failure may leave no grant, audit or
// session revocation; the same administrative action must then be retryable.
$extraRevision = estab_user_admin_extra_functions_revision([]);
$extraSchemaLockName = estab_dynamic_schema_lock_name(
    $database,
    'usr__fkt_s1'
);
$escapedExtraSchemaLockName = mysql_real_escape_string(
    $extraSchemaLockName,
    $link
);
test_assert(
    test_scalar(
        "SELECT GET_LOCK('{$escapedExtraSchemaLockName}', 0)",
        $link
    ) === '1',
    'Could not hold extra-function schema lock'
);
$grantConnection = estab_auth_connect($provisionConfig);
$grantFailure = null;
try {
    $extraRoles = estab_user_admin_extra_function_roles(
        $grantConnection,
        'nv_empfmtx'
    );
    test_assert(
        ($extraRoles['ETB'] ?? null) === 'Stab'
            && ($extraRoles['S1'] ?? null) === 'Stab',
        'ETB logbook-only or S1 staff extra function disappeared'
    );
    estab_user_admin_grant_extra_function(
        $grantConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $registrationCode,
        'S1',
        'S2',
        'Stab',
        $extraRevision,
        '1',
        'nv_empfmtx',
        'usr_',
        'dynamic-table-integration',
        '127.0.0.1',
        true
    );
} catch (RuntimeException $exception) {
    $grantFailure = $exception->getMessage();
} finally {
    estab_auth_close($grantConnection);
}
$quotedExtraGrantUserTables = implode(',', array_map(
    static fn (string $table): string => "'" . $table . "'",
    $extraGrantUserTables
));
test_assert(
    is_string($grantFailure)
        && str_contains($grantFailure, 'Dynamische Tabellen')
        && test_scalar(
            "SELECT COUNT(*) FROM `nv_benutzer_zusatzfunktionen`"
                . " WHERE `benutzer_kuerzel` = '{$registrationCode}'"
                . " AND `funktion` = 'S1'",
            $link
        ) === '0'
        && test_scalar(
            "SELECT COUNT(*) FROM information_schema.tables"
                . " WHERE table_schema = '{$escapedDatabase}'"
                . " AND table_name IN ({$quotedExtraGrantUserTables})",
            $link
        ) === '0'
        && test_scalar(
            "SELECT COUNT(*) FROM `nv_benutzer`"
                . " WHERE `kuerzel` = '{$registrationCode}'"
                . " AND `aktiv` = 1 AND `sid` = '"
                . mysql_real_escape_string(
                    $secondAuthenticatedSessionId,
                    $link
                ) . "'",
            $link
        ) === '1'
        && test_scalar(
            "SELECT COUNT(*) FROM `nv_protokoll`"
                . " WHERE `p_was` = 'Benutzerverwaltung'"
                . " AND `p_ereignis` LIKE '%grant_extra_function%'"
                . " AND `p_ereignis` LIKE '%\"target\":\""
                . $registrationCode . "\"%'",
            $link
        ) === '0',
    'failed extra-function schema reconciliation granted authority, audited '
        . 'success or revoked the active session'
);
test_assert(
    test_scalar(
        "SELECT RELEASE_LOCK('{$escapedExtraSchemaLockName}')",
        $link
    ) === '1',
    'Could not release extra-function schema lock'
);

$grantConnection = estab_auth_connect($provisionConfig);
try {
    $extraGrant = estab_user_admin_grant_extra_function(
        $grantConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $registrationCode,
        'S1',
        'S2',
        'Stab',
        $extraRevision,
        '1',
        'nv_empfmtx',
        'usr_',
        'dynamic-table-integration',
        '127.0.0.1',
        true
    );
} finally {
    estab_auth_close($grantConnection);
}
test_assert(
    ($extraGrant['funktion'] ?? null) === 'S1'
        && ($extraGrant['rolle'] ?? null) === 'Stab'
        && ($extraGrant['active_session_revoked'] ?? null) === true
        && test_scalar(
            "SELECT COUNT(*) FROM `nv_benutzer_zusatzfunktionen`"
                . " WHERE `benutzer_kuerzel` = '{$registrationCode}'"
                . " AND `funktion` = 'S1' AND `rolle` = 'Stab'",
            $link
        ) === '1'
        && test_scalar(
            "SELECT COUNT(*) FROM information_schema.tables"
                . " WHERE table_schema = '{$escapedDatabase}'"
                . " AND table_name IN ({$quotedExtraGrantUserTables})",
            $link
        ) === '3'
        && test_scalar(
            "SELECT COUNT(*) FROM `nv_benutzer`"
                . " WHERE `kuerzel` = '{$registrationCode}'"
                . " AND `aktiv` = 0 AND `sid` = ''",
            $link
        ) === '1'
        && test_scalar(
            "SELECT COUNT(*) FROM `nv_protokoll`"
                . " WHERE `p_was` = 'Benutzerverwaltung'"
                . " AND `p_ereignis` LIKE '%grant_extra_function%'"
                . " AND `p_ereignis` LIKE '%\"target\":\""
                . $registrationCode . "\"%'",
            $link
        ) === '1',
    'successful extra-function grant did not atomically provision, audit and '
        . 'revoke the active session'
);

$databaseFailureSession = $secondAuthenticatedSession;
test_assert(
    estab_auth_current_session_identity(
        $databaseFailureSession,
        array_replace(
            $validationDatabase,
            ['datenbank' => 'estab_missing_session_validation_database']
        ),
        'nv_benutzer',
        $secondAuthenticatedSessionId
    ) === null
        && $databaseFailureSession === ['menue' => 'LOGIN'],
    'Session validation did not fail closed when its database was unavailable'
);

test_query(
    "DELETE FROM `nv_benutzer` WHERE `kuerzel` = '{$registrationCode}'",
    $link
);
test_query(
    "DELETE FROM `nv_protokoll`"
    . " WHERE `p_ereignis` LIKE '%\"target\":\"{$registrationCode}\"%'",
    $link
);
test_query(
    "DELETE FROM `nv_protokoll`"
    . " WHERE `p_was` = 'Benutzerverwaltung'"
    . " AND `p_ereignis` LIKE '%\"target\":\"{$registrationCode}\"%'",
    $link
);
foreach ($registrationUserTables as $registrationTable) {
    test_query("DROP TABLE IF EXISTS `{$registrationTable}`", $link);
}
$_SESSION = [];
session_destroy();
$currentSessionCookie = session_name();
unset($_COOKIE[$currentSessionCookie]);
session_id($firstAuthenticatedSessionId);
if (session_start()) {
    $_SESSION = [];
    session_destroy();
}
test_assert(chdir($originalDirectory), 'Could not restore integration directory');

mysql_close($link);

drop_fixture_tables($server, $database, $username, $password, $tables);
$verification = test_connect($server, $database, $username, $password);
test_assert(
    test_scalar(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = '{$escapedDatabase}'
            AND table_name IN ({$quotedTables})",
        $verification
    ) === '0',
    'Fixture tables were not removed'
);
mysql_close($verification);

echo "dynamic table migration integration test: OK\n";
