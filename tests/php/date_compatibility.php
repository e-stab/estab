<?php

require_once __DIR__ . '/../../app/datetime.php';

function date_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    date_test_assert($condition, $message);
};

foreach ([null, '', '  ', '0000-00-00', '0000-00-00 00:00:00'] as $unsetValue) {
    $assert(estab_datetime_is_unset($unsetValue), 'Unset date representation was not recognised');
    $assert(estab_datetime_parts($unsetValue)['date'] === '', 'Unset date was parsed');
}
$assert(!estab_datetime_is_unset('2026-07-23 09:08:07'), 'Valid date was treated as unset');
$assert(!estab_datetime_is_unset('0'), 'Unrelated string was treated as an SQL date');

$parts = estab_datetime_parts('2024-02-29 23:59:58');
$assert($parts['date'] === '2024-02-29', 'Database date part changed');
$assert($parts['time'] === '23:59:58', 'Database time part changed');
$assert($parts['datum'] === '2902', 'Legacy date notation changed');
$assert($parts['zeit'] === '2359', 'Legacy time notation changed');
$assert($parts['stak'] === '292359', 'Short tactical time changed');

$months = ['02' => 'feb'];
$assert(
    estab_datetime_to_tactical('2024-02-29 23:59:58', $months) === '292359feb2024',
    'Long tactical time changed or lost its historic year'
);
$assert(estab_datetime_to_tactical(null, $months) === '', 'NULL tactical time is not empty');
$assert(estab_datetime_to_tactical('0000-00-00 00:00:00', $months) === '', 'Zero tactical time is not empty');

foreach (['2023-02-29 12:00:00', '2024-13-01 00:00:00', '2024-01-01 24:00:00', 'invalid'] as $invalid) {
    $assert(estab_datetime_parts($invalid)['date'] === '', 'Invalid date was accepted: ' . $invalid);
}

$root = dirname(__DIR__, 2);
$queueFiles = [
    $root . '/4fach/tools.php',
    $root . '/4fach/tools_neu.php',
    $root . '/4fach/dummy.php',
    $root . '/4fach/liste.php',
    $root . '/4fach/logoff.php',
];
foreach ($queueFiles as $file) {
    $source = file_get_contents($file);
    $assert($source !== false, 'Could not read ' . $file);
    $assert(
        preg_match('/`(?:03_datum|15_quitdatum)`\s*(?:!?=|<>)\s*0\b/', (string) $source) !== 1,
        basename($file) . ' still compares a nullable DATETIME numerically with zero'
    );
}

$toolsSource = (string) file_get_contents($root . '/4fach/tools.php');
$listSource = (string) file_get_contents($root . '/4fach/liste.php');
$logoffSource = (string) file_get_contents($root . '/4fach/logoff.php');
foreach ([$toolsSource, $listSource, $logoffSource] as $source) {
    $assert(
        preg_match('/`15_quitdatum`\s+IS\s+NULL/', $source) === 1,
        'Pending-viewer filter lost NULL semantics'
    );
    $assert(
        preg_match('/`03_datum`\s+IS\s+NOT\s+NULL/', $source) === 1,
        'Forwarded-message filter lost NULL semantics'
    );
}
$assert(preg_match('/`03_datum`\s+IS\s+NULL/', $toolsSource) === 1, 'Outgoing queue lost NULL semantics');
$assert(preg_match('/`03_datum`\s+IS\s+NULL/', $listSource) === 1, 'Outgoing list lost NULL semantics');

foreach (['backup_pdf.php', 'backup_img.php'] as $backupFile) {
    $source = (string) file_get_contents($root . '/4fbak/' . $backupFile);
    $assert(str_contains($source, 'estab_datetime_is_unset'), $backupFile . ' does not guard nullable dates');
    $assert(!str_contains($source, '0000-00-00 00:00:00'), $backupFile . ' still special-cases only zero dates');
}

foreach (['4fadm/create_db.php', '4fach/create_db.php'] as $legacySchema) {
    $source = (string) file_get_contents($root . '/' . $legacySchema);
    $assert(!str_contains($source, "default '0000-00-00 00:00:00'"), $legacySchema . ' is not Strict-Mode compatible');
}

$migration = (string) file_get_contents($root . '/docker/db/migrations/20-nullable-dates.sql');
foreach (['01_datum', '02_zeit', '03_datum', '12_abfzeit', '15_quitdatum', 'x05_druck_d', '99_lstacc', 'date', 'sich1_zeit', 'sich2_zeit', 'sich3_zeit', 'sich4_zeit', 'trans_start'] as $column) {
    $assert(str_contains($migration, '`' . $column . '`'), 'Migration omits ' . $column);
}
$assert(str_contains($migration, 'SET SESSION sql_mode = @estab_previous_sql_mode'), 'Migration does not restore SQL mode');

$verify = (string) file_get_contents($root . '/docker/db/verify.sql');
$assert(str_contains($verify, 'no_zero_date_values_ok'), 'Database verification ignores stored zero dates');

echo "date compatibility tests: OK ({$assertions} assertions)\n";
