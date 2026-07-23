<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../4fach/db_operation.php';

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
test_assert($methodLink instanceof mysqli, 'create_user_table lost its connection');
$restoredMode = test_scalar('SELECT @@SESSION.sql_mode', $methodLink) ?? '';
foreach (explode(',', $strictMode) as $requiredMode) {
    test_assert(str_contains($restoredMode, $requiredMode), 'SQL mode not restored: ' . $requiredMode);
}
mysql_close($methodLink);

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
