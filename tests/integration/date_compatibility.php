<?php

require_once __DIR__ . '/../../app/bootstrap.php';

function date_db_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function date_db_scalar(mysqli $database, string $sql): string|null
{
    $result = $database->query($sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Expected result set for: ' . $sql);
    }
    $row = $result->fetch_row();
    $result->free();
    return $row === null ? null : (string) $row[0];
}

function date_db_run_script(mysqli $database, string $sql): void
{
    $database->multi_query($sql);
    do {
        $result = $database->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($database->more_results() && $database->next_result());
}

$host = getenv('ESTAB_TEST_DB_HOST') ?: (getenv('ESTAB_DB_HOST') ?: '127.0.0.1');
$port = (int) (getenv('ESTAB_TEST_DB_PORT') ?: (getenv('ESTAB_DB_PORT') ?: 3306));
$name = getenv('ESTAB_TEST_DB_NAME') ?: (getenv('ESTAB_DB_NAME') ?: 'estab');
$user = getenv('ESTAB_TEST_DB_USER') ?: (getenv('ESTAB_DB_USER') ?: 'estab');
$password = getenv('ESTAB_TEST_DB_PASSWORD') ?: (getenv('ESTAB_DB_PASSWORD') ?: '');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = new mysqli($host, $user, $password, $name, $port);
$database->set_charset('utf8mb4');
$strictMode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
$database->query("SET SESSION sql_mode = '{$strictMode}'");
$modeBeforeMigration = date_db_scalar($database, 'SELECT @@SESSION.sql_mode');

// Reproduce the relevant legacy columns with their former zero defaults. The
// production migration is then applied verbatim after replacing only table
// identifiers with connection-local temporary fixtures.
$database->query("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
$database->query(
    "CREATE TEMPORARY TABLE `estab_test_legacy_nachrichten` (
      `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `01_datum` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
      `02_zeit` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
      `03_datum` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
      `12_abfzeit` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
      `15_quitdatum` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
      `x05_druck_d` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
      `99_lstacc` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"
);
$database->query(
    "CREATE TEMPORARY TABLE `estab_test_legacy_anhang` (
      `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `date` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00'
    ) ENGINE=InnoDB"
);
$database->query(
    "CREATE TEMPORARY TABLE `estab_test_legacy_bhp50` (
      `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `sich1_zeit` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE CURRENT_TIMESTAMP,
      `sich2_zeit` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
      `sich3_zeit` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
      `sich4_zeit` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
      `trans_start` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00'
    ) ENGINE=InnoDB"
);

$database->query('INSERT INTO `estab_test_legacy_nachrichten` (`id`) VALUES (1)');
$database->query(
    "INSERT INTO `estab_test_legacy_nachrichten`
      (`id`, `01_datum`, `02_zeit`, `03_datum`, `12_abfzeit`, `15_quitdatum`, `x05_druck_d`, `99_lstacc`)
     VALUES (2, '2018-01-02 03:04:05', '2018-02-03 04:05:06', '2018-03-04 05:06:07',
             '2018-04-05 06:07:08', '2018-05-06 07:08:09', '2018-06-07 08:09:10', '2018-07-08 09:10:11')"
);
$database->query('INSERT INTO `estab_test_legacy_anhang` (`id`) VALUES (1)');
$database->query("INSERT INTO `estab_test_legacy_anhang` (`id`, `date`) VALUES (2, '2019-01-02 03:04:05')");
$database->query('INSERT INTO `estab_test_legacy_bhp50` (`id`) VALUES (1)');
$database->query(
    "INSERT INTO `estab_test_legacy_bhp50`
      (`id`, `sich1_zeit`, `sich2_zeit`, `sich3_zeit`, `sich4_zeit`, `trans_start`)
     VALUES (2, '2020-01-02 03:04:05', '2020-02-03 04:05:06', '2020-03-04 05:06:07',
             '2020-04-05 06:07:08', '2020-05-06 07:08:09')"
);
$database->query("SET SESSION sql_mode = '{$strictMode}'");

$migration = file_get_contents(__DIR__ . '/../../docker/db/migrations/20-nullable-dates.sql');
date_db_assert($migration !== false, 'Could not read zero-date migration');
$fixtureMigration = str_replace(
    ['`nv_nachrichten`', '`nv_anhang`', '`nv_bhp50`'],
    ['`estab_test_legacy_nachrichten`', '`estab_test_legacy_anhang`', '`estab_test_legacy_bhp50`'],
    $migration
);
date_db_run_script($database, $fixtureMigration);
date_db_run_script($database, $fixtureMigration); // idempotency

date_db_assert(date_db_scalar($database, 'SELECT @@SESSION.sql_mode') === $modeBeforeMigration, 'Migration did not restore SQL mode');
date_db_assert(
    date_db_scalar(
        $database,
        "SELECT (`01_datum` IS NULL) + (`02_zeit` IS NULL) + (`03_datum` IS NULL)
              + (`12_abfzeit` IS NULL) + (`15_quitdatum` IS NULL) + (`x05_druck_d` IS NULL)
              + (`99_lstacc` IS NULL)
           FROM `estab_test_legacy_nachrichten` WHERE `id` = 1"
    ) === '7',
    'A legacy message zero date was not converted to NULL'
);
date_db_assert(
    date_db_scalar(
        $database,
        "SELECT DATE_FORMAT(`99_lstacc`, '%Y-%m-%d %H:%i:%s')
           FROM `estab_test_legacy_nachrichten` WHERE `id` = 2"
    ) === '2018-07-08 09:10:11',
    'Migration replaced a valid ON UPDATE timestamp'
);
date_db_assert(
    date_db_scalar($database, 'SELECT `date` IS NULL FROM `estab_test_legacy_anhang` WHERE `id` = 1') === '1',
    'Legacy attachment zero date was not converted to NULL'
);
date_db_assert(
    date_db_scalar(
        $database,
        'SELECT (`sich1_zeit` IS NULL) + (`sich2_zeit` IS NULL) + (`sich3_zeit` IS NULL) + (`sich4_zeit` IS NULL) + (`trans_start` IS NULL) FROM `estab_test_legacy_bhp50` WHERE `id` = 1'
    ) === '5',
    'A legacy BHP-50 zero date was not converted to NULL'
);
date_db_assert(
    date_db_scalar(
        $database,
        "SELECT DATE_FORMAT(`trans_start`, '%Y-%m-%d %H:%i:%s') FROM `estab_test_legacy_bhp50` WHERE `id` = 2"
    ) === '2020-05-06 07:08:09',
    'Migration changed a valid BHP-50 date'
);

// Prove the three operational queue predicates against representative message
// states without touching production tables.
$database->query(
    "CREATE TEMPORARY TABLE `estab_test_date_queue` (
      `id` INT NOT NULL PRIMARY KEY,
      `04_richtung` CHAR(1) NOT NULL,
      `03_datum` DATETIME NULL DEFAULT NULL,
      `03_zeichen` CHAR(3) NOT NULL DEFAULT '',
      `15_quitdatum` DATETIME NULL DEFAULT NULL,
      `15_quitzeichen` CHAR(3) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB"
);
$database->query(
    "INSERT INTO `estab_test_date_queue`
      (`id`, `04_richtung`, `03_datum`, `03_zeichen`, `15_quitdatum`, `15_quitzeichen`) VALUES
      (1, 'A', NULL, '', NULL, ''),
      (2, 'A', '2026-07-23 08:00:00', 'OP', NULL, ''),
      (3, 'E', NULL, '', NULL, ''),
      (4, 'E', NULL, '', '2026-07-23 09:00:00', 'OP'),
      (5, 'E', NULL, '', NULL, 'OP')"
);
date_db_assert(
    date_db_scalar($database, "SELECT COUNT(*) FROM `estab_test_date_queue` WHERE `04_richtung` = 'A' AND `03_datum` IS NULL AND `03_zeichen` = ''") === '1',
    'Outgoing queue does not select exactly the unforwarded message'
);
date_db_assert(
    date_db_scalar($database, "SELECT COUNT(*) FROM `estab_test_date_queue` WHERE `15_quitdatum` IS NULL AND `15_quitzeichen` = '' AND `04_richtung` = 'E'") === '1',
    'Incoming viewer queue does not select exactly the unacknowledged message'
);
date_db_assert(
    date_db_scalar($database, "SELECT COUNT(*) FROM `estab_test_date_queue` WHERE `15_quitdatum` IS NULL AND `15_quitzeichen` = '' AND (`04_richtung` = 'E' OR (`03_datum` IS NOT NULL AND `03_zeichen` != ''))") === '2',
    'Combined viewer queue lost the forwarded outgoing message'
);

$database->close();
echo "date compatibility integration test: OK\n";
