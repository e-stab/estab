<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/incident.php';
require_once dirname(__DIR__, 2) . '/app/message_list.php';

const ESTAB_MESSAGE_LIST_SCALE_DATABASE = 'estab_message_list_scale_ci_test';
const ESTAB_MESSAGE_LIST_SCALE_TARGET_ROWS = 10000;
const ESTAB_MESSAGE_LIST_SCALE_FOREIGN_ROWS = 257;

// These are deliberately generous regression guards, not production SLAs.
// They catch accidental unbounded PHP loading/table scans while remaining
// usable on emulated arm64 CI runners and rootless Podman Desktop VMs.
const ESTAB_MESSAGE_LIST_SCALE_SEED_SECONDS = 180.0;
const ESTAB_MESSAGE_LIST_SCALE_QUERY_SECONDS = 5.0;
const ESTAB_MESSAGE_LIST_SCALE_BENCHMARK_SECONDS = 45.0;

$databaseName = getenv('ESTAB_DB_NAME') ?: '';
if ($databaseName !== ESTAB_MESSAGE_LIST_SCALE_DATABASE) {
    fwrite(
        STDERR,
        "Refusing to run message-list scale integration outside its isolated database\n"
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
    fwrite(STDERR, "Message-list scale database password is required\n");
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

$assertions = 0;
$assert = static function (
    bool $condition,
    string $message
) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/**
 * Execute a native prepared statement and return all rows.
 *
 * @param list<int|string> $parameters
 * @return list<array<string, mixed>>
 */
$preparedRows = static function (
    mysqli $database,
    string $sql,
    array $parameters = []
): array {
    $statement = $database->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('Could not prepare message-list scale query');
    }
    try {
        $statement->execute($parameters);
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException(
                'Message-list scale query did not return a result set'
            );
        }
        try {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
        return $rows;
    } finally {
        $statement->close();
    }
};

/**
 * Execute the same COUNT/page shape as the production list call sites.
 *
 * Every request-derived value comes from estab_message_list_filter_sql() and
 * is bound to a native prepared statement. LIMIT and OFFSET are bound too.
 *
 * @return array{
 *   count:int,
 *   window:array<string, int|bool>,
 *   rows:list<array<string, mixed>>,
 *   duration:float,
 *   sql:string,
 *   params:list<int|string>
 * }
 */
$runList = static function (
    mysqli $database,
    int $incidentId,
    array $filters
) use ($preparedRows): array {
    $filters = array_replace(estab_message_list_default_filters(), $filters);
    $filter = estab_message_list_filter_sql($filters, 'm');
    $where = 'm.`einsatz_id` = ?';
    $parameters = [$incidentId];
    if ($filter['sql'] !== '') {
        $where .= ' AND ' . $filter['sql'];
        array_push($parameters, ...$filter['params']);
    }

    $started = hrtime(true);
    $countRows = $preparedRows(
        $database,
        'SELECT COUNT(*) AS `treffer` FROM `nv_nachrichten` AS m'
            . ' WHERE ' . $where,
        $parameters
    );
    $count = (int) ($countRows[0]['treffer'] ?? -1);
    $window = estab_message_list_page_window($count, $filters);
    $sql = 'SELECT m.`00_lfd`, m.`einsatz_id`, m.`04_richtung`,'
        . ' m.`04_nummer`, m.`09_vorrangstufe`, m.`12_betreff`,'
        . ' m.`12_inhalt`, m.`12_abfzeit`, m.`16_empf`, m.`x00_status`'
        . ' FROM `nv_nachrichten` AS m WHERE ' . $where
        . ' ORDER BY ' . estab_message_list_order_sql($filters, 'm')
        . ' LIMIT ? OFFSET ?';
    $pageParameters = $parameters;
    $pageParameters[] = (int) $window['page_size'];
    $pageParameters[] = (int) $window['offset'];
    $rows = $preparedRows($database, $sql, $pageParameters);
    $duration = (hrtime(true) - $started) / 1_000_000_000;

    return [
        'count' => $count,
        'window' => $window,
        'rows' => $rows,
        'duration' => $duration,
        'sql' => $sql,
        'params' => $pageParameters,
    ];
};

/** @return list<string> */
$explainKeys = static function (
    mysqli $database,
    string $sql,
    array $parameters
) use ($preparedRows): array {
    $rows = $preparedRows($database, 'EXPLAIN ' . $sql, $parameters);
    $keys = [];
    foreach ($rows as $row) {
        $key = $row['key'] ?? null;
        if (is_string($key) && $key !== '' && !in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }
    return $keys;
};

$createIncident = static function (
    mysqli $database,
    string $suffix,
    bool $activate
): array {
    $status = estab_incident_status($database);
    return estab_incident_create(
        $database,
        [
            'kennung' => 'LIST-SCALE-' . $suffix,
            'name' => 'Nachrichtenlisten-Lasttest ' . $suffix,
            'beginn' => '2026-01-01T00:00',
            'ort' => 'Isolierte MariaDB-Integration',
            'organisation' => 'THW',
            'fuehrungsstellenname' => 'Führungsstelle Lasttest ' . $suffix,
            'einsatzleitung' => 'CI',
            'beschreibung' => 'Nur im wegwerfbaren CI-Datenbestand',
        ],
        'message-list-scale-integration',
        $activate,
        $activate ? (int) $status['revision'] : null
    );
};

$activateIncident = static function (
    mysqli $database,
    int $incidentId
): void {
    $status = estab_incident_status($database);
    estab_incident_activate(
        $database,
        $incidentId,
        (int) $status['revision'],
        'message-list-scale-integration'
    );
};

/**
 * Insert deterministic rows through one reused native prepared statement.
 *
 * @return list<array{
 *   id:int,number:int,direction:string,priority:string,status:int,
 *   time:string,recipient:string,fulltext:bool,short:bool
 * }>
 */
$insertMessages = static function (
    mysqli $database,
    int $incidentId,
    int $rowCount,
    bool $foreign
): array {
    $statement = $database->prepare(
        'INSERT INTO `nv_nachrichten`'
            . ' (`einsatz_id`, `04_richtung`, `04_nummer`,'
            . ' `05_gegenstelle`, `09_vorrangstufe`, `10_anschrift`,'
            . ' `11_rufnummer`, `12_betreff`, `12_inhalt`,'
            . ' `12_abfzeit`, `13_abseinheit`, `14_funktion`,'
            . ' `16_empf`, `x00_status`, `x01_abschluss`, `x04_druck`)'
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 't', 't')"
    );
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('Could not prepare scale fixture insert');
    }

    $records = [];
    $priorities = ['', 'sss', 'bbb', 'aaa', 'eee'];
    $statuses = [0, 1, 2, 4, 8, 10];
    $base = strtotime($foreign ? '2025-12-01 00:00:00 UTC' : '2026-01-01 00:00:00 UTC');
    if (!is_int($base)) {
        throw new RuntimeException('Could not construct scale fixture time');
    }

    if (!$database->begin_transaction()) {
        throw new RuntimeException('Could not begin scale fixture transaction');
    }
    try {
        for ($index = 1; $index <= $rowCount; $index++) {
            $direction = $index % 2 === 0 ? 'E' : 'A';
            $number = $foreign ? 900000 + $index : $index;
            $priority = $priorities[($index - 1) % count($priorities)];
            $status = $statuses[($index - 1) % count($statuses)];
            $time = gmdate(
                'Y-m-d H:i:s',
                $base + intdiv($index - 1, 4) * 300
            );
            $fulltext = $foreign || $index % 137 === 0;
            $short = !$foreign && $index % 211 === 0;
            $counterparty = $index % 2 === 0
                ? 'Leitstelle Nord'
                : 'Bereitstellungsraum Süd';
            $address = $direction === 'E'
                ? 'Führungsstelle Alpha'
                : 'Abschnitt Versorgung';
            $phone = 'Telefon intern';
            $subject = $fulltext
                ? 'Sondermarker Nord Abschnitt'
                : 'Regelmäßiger Lagebericht';
            $content = $short
                ? 'Kurzcode XY bestätigt'
                : 'Routineinhalt ohne Ziffernfolge';
            if ($foreign) {
                $content = 'Fremdeinsatz darf niemals im Ziel erscheinen';
            }
            $sender = $index % 2 === 0 ? 'Einheit Nord' : 'Einheit Süd';
            $function = match ($index % 3) {
                0 => 'S2',
                1 => 'A/W',
                default => 'S3',
            };
            $recipient = match ($index % 3) {
                0 => 'S2_rt,S3_gn,',
                1 => 'A/W_gn,',
                default => 'alle,',
            };

            $statement->execute([
                $incidentId,
                $direction,
                $number,
                $counterparty,
                $priority,
                $address,
                $phone,
                $subject,
                $content,
                $time,
                $sender,
                $function,
                $recipient,
                $status,
            ]);
            if (!$foreign) {
                $records[] = [
                    'id' => (int) $database->insert_id,
                    'number' => $number,
                    'direction' => $direction,
                    'priority' => $priority,
                    'status' => $status,
                    'time' => $time,
                    'recipient' => $recipient,
                    'fulltext' => $fulltext,
                    'short' => $short,
                ];
            }
        }
        if (!$database->commit()) {
            throw new RuntimeException('Could not commit scale fixture');
        }
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    } finally {
        $statement->close();
    }
    return $records;
};

$indexColumns = static function (
    array $rows,
    string $name
): array {
    $columns = [];
    foreach ($rows as $row) {
        if (($row['index_name'] ?? null) !== $name) {
            continue;
        }
        $columns[(int) $row['seq_in_index']] = (string) $row['column_name'];
    }
    ksort($columns);
    return array_values($columns);
};

$maximumQueryDuration = 0.0;
$benchmarkDuration = 0.0;
$seedDuration = 0.0;

try {
    $migration = $preparedRows(
        $connection,
        'SELECT `state`, `checksum` FROM `estab_schema_migrations`'
            . ' WHERE `version` = ?',
        ['99-message-list-search.sql']
    );
    $assert(
        count($migration) === 1
            && ($migration[0]['state'] ?? null) === 'applied'
            && preg_match(
                '/\A[0-9a-f]{64}\z/D',
                (string) ($migration[0]['checksum'] ?? '')
            ) === 1,
        'message-list search migration is not recorded as applied'
    );

    $indexes = $preparedRows(
        $connection,
        'SELECT `index_name`, `seq_in_index`, `column_name`, `index_type`'
            . ' FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE() AND table_name = ?'
            . ' AND index_name IN (?, ?, ?, ?)'
            . ' ORDER BY `index_name`, `seq_in_index`',
        [
            'nv_nachrichten',
            'ft_nachrichten_inhalt',
            'ft_nachrichten_suche',
            'idx_nachrichten_einsatz_status_zeit',
            'idx_nachrichten_einsatz_richtung_nummer',
        ]
    );
    $assert(
        $indexColumns($indexes, 'ft_nachrichten_inhalt') === [],
        'obsolete content-only full-text index is still present'
    );
    $assert(
        $indexColumns($indexes, 'ft_nachrichten_suche') === [
            '05_gegenstelle',
            '10_anschrift',
            '11_rufnummer',
            '12_betreff',
            '12_inhalt',
            '13_abseinheit',
            '14_funktion',
        ],
        'message-list full-text index has the wrong column order'
    );
    $assert(
        $indexColumns($indexes, 'idx_nachrichten_einsatz_status_zeit') === [
            'einsatz_id',
            'x00_status',
            '12_abfzeit',
            '00_lfd',
        ],
        'message-list status/time index has the wrong column order'
    );
    $assert(
        $indexColumns($indexes, 'idx_nachrichten_einsatz_richtung_nummer') === [
            'einsatz_id',
            '04_richtung',
            '04_nummer',
            '00_lfd',
        ],
        'message-list direction/number index has the wrong column order'
    );
    $indexTypes = [];
    foreach ($indexes as $index) {
        $indexTypes[(string) $index['index_name']] = (string) $index['index_type'];
    }
    $assert(
        ($indexTypes['ft_nachrichten_suche'] ?? null) === 'FULLTEXT'
            && ($indexTypes['idx_nachrichten_einsatz_status_zeit'] ?? null) === 'BTREE'
            && ($indexTypes['idx_nachrichten_einsatz_richtung_nummer'] ?? null) === 'BTREE',
        'message-list search indexes have incompatible index types'
    );

    $seedStarted = hrtime(true);
    $foreignIncident = $createIncident($connection, 'FOREIGN', true);
    $foreignIncidentId = (int) $foreignIncident['einsatz_id'];
    $insertMessages(
        $connection,
        $foreignIncidentId,
        ESTAB_MESSAGE_LIST_SCALE_FOREIGN_ROWS,
        true
    );

    $targetIncident = $createIncident($connection, 'TARGET', false);
    $targetIncidentId = (int) $targetIncident['einsatz_id'];
    $activateIncident($connection, $targetIncidentId);
    $records = $insertMessages(
        $connection,
        $targetIncidentId,
        ESTAB_MESSAGE_LIST_SCALE_TARGET_ROWS,
        false
    );
    $seedDuration = (hrtime(true) - $seedStarted) / 1_000_000_000;
    $assert(
        count($records) === ESTAB_MESSAGE_LIST_SCALE_TARGET_ROWS,
        'scale fixture did not retain exactly 10,000 target records'
    );
    $assert(
        $seedDuration <= ESTAB_MESSAGE_LIST_SCALE_SEED_SECONDS,
        sprintf(
            '10,257-row fixture exceeded the %.0f-second seed guard (%.3fs)',
            ESTAB_MESSAGE_LIST_SCALE_SEED_SECONDS,
            $seedDuration
        )
    );

    $analyse = $connection->query('ANALYZE TABLE `nv_nachrichten`');
    if ($analyse instanceof mysqli_result) {
        $analyse->free();
    }

    $unfiltered = $runList(
        $connection,
        $targetIncidentId,
        ['sort' => 'newest', 'page_size' => 25, 'page' => 1]
    );
    $maximumQueryDuration = max($maximumQueryDuration, $unfiltered['duration']);
    $assert(
        $unfiltered['count'] === ESTAB_MESSAGE_LIST_SCALE_TARGET_ROWS,
        'unfiltered count crossed the incident boundary'
    );
    $assert(
        array_map(
            static fn (array $row): int => (int) $row['04_nummer'],
            $unfiltered['rows']
        ) === range(10000, 9976),
        'newest page is not stably ordered by timestamp and row id'
    );

    $pageTwoFilters = ['sort' => 'newest', 'page_size' => 25, 'page' => 2];
    $pageTwo = $runList($connection, $targetIncidentId, $pageTwoFilters);
    $pageTwoRepeat = $runList($connection, $targetIncidentId, $pageTwoFilters);
    $maximumQueryDuration = max(
        $maximumQueryDuration,
        $pageTwo['duration'],
        $pageTwoRepeat['duration']
    );
    $pageOneIds = array_column($unfiltered['rows'], '00_lfd');
    $pageTwoIds = array_column($pageTwo['rows'], '00_lfd');
    $assert(
        $pageTwoIds === array_column($pageTwoRepeat['rows'], '00_lfd'),
        'repeated stable page returned a different row sequence'
    );
    $assert(
        array_intersect($pageOneIds, $pageTwoIds) === [],
        'stable adjacent pages overlap'
    );
    $assert(
        array_map(
            static fn (array $row): int => (int) $row['04_nummer'],
            $pageTwo['rows']
        ) === range(9975, 9951),
        'second stable page returned the wrong message numbers'
    );

    $combinedFilters = [
        'direction' => 'E',
        'priority' => 'bbb',
        'status' => 'review',
        'from' => '2026-01-04',
        'to' => '2026-01-06',
        'recipient' => 'A/W',
        'sort' => 'number_asc',
        'page_size' => 100,
    ];
    $combinedExpected = array_values(array_filter(
        $records,
        static fn (array $record): bool =>
            $record['direction'] === 'E'
            && $record['priority'] === 'bbb'
            && $record['status'] === 4
            && $record['time'] >= '2026-01-04 00:00:00'
            && $record['time'] < '2026-01-07 00:00:00'
            && (
                str_contains($record['recipient'], 'A/W_')
                || str_contains($record['recipient'], 'alle,')
            )
    ));
    usort(
        $combinedExpected,
        static fn (array $left, array $right): int =>
            $left['number'] <=> $right['number']
                ?: $left['id'] <=> $right['id']
    );
    $combined = $runList($connection, $targetIncidentId, $combinedFilters);
    $maximumQueryDuration = max($maximumQueryDuration, $combined['duration']);
    $assert(
        $combined['count'] === count($combinedExpected)
            && $combined['count'] > 0,
        'combined structured filters returned an incorrect count'
    );
    $assert(
        array_map(
            static fn (array $row): int => (int) $row['04_nummer'],
            $combined['rows']
        ) === array_slice(array_column($combinedExpected, 'number'), 0, 100),
        'combined structured filters returned incorrect or unstable rows'
    );

    $fulltextExpected = array_values(array_filter(
        $records,
        static fn (array $record): bool => $record['fulltext']
    ));
    $fulltext = $runList(
        $connection,
        $targetIncidentId,
        [
            'q' => 'Sondermarker Nord',
            'sort' => 'number_asc',
            'page_size' => 100,
        ]
    );
    $maximumQueryDuration = max($maximumQueryDuration, $fulltext['duration']);
    $assert(
        $fulltext['count'] === count($fulltextExpected)
            && $fulltext['count'] === intdiv(10000, 137),
        'full-text prefix search returned wrong target-incident count'
    );
    $assert(
        array_map(
            static fn (array $row): int => (int) $row['04_nummer'],
            $fulltext['rows']
        ) === array_column($fulltextExpected, 'number'),
        'full-text prefix search returned wrong rows'
    );

    $shortExpected = array_values(array_filter(
        $records,
        static fn (array $record): bool => $record['short']
    ));
    $short = $runList(
        $connection,
        $targetIncidentId,
        ['q' => 'XY', 'sort' => 'number_asc', 'page_size' => 100]
    );
    $maximumQueryDuration = max($maximumQueryDuration, $short['duration']);
    $assert(
        $short['count'] === count($shortExpected)
            && array_map(
                static fn (array $row): int => (int) $row['04_nummer'],
                $short['rows']
            ) === array_column($shortExpected, 'number'),
        'short-token literal search returned wrong rows'
    );

    $numberExpected = array_values(array_filter(
        $records,
        static fn (array $record): bool =>
            $record['number'] === 4242 || $record['id'] === 4242
    ));
    usort(
        $numberExpected,
        static fn (array $left, array $right): int =>
            $left['number'] <=> $right['number']
                ?: $left['id'] <=> $right['id']
    );
    $number = $runList(
        $connection,
        $targetIncidentId,
        ['q' => '4242', 'sort' => 'number_asc', 'page_size' => 25]
    );
    $maximumQueryDuration = max($maximumQueryDuration, $number['duration']);
    $assert(
        $number['count'] === count($numberExpected)
            && $number['count'] === 2,
        'exact message/row number search returned wrong count'
    );
    $assert(
        array_map(
            static fn (array $row): int => (int) $row['00_lfd'],
            $number['rows']
        ) === array_column($numberExpected, 'id'),
        'exact message/row number search returned wrong rows'
    );

    $lastPage = $runList(
        $connection,
        $targetIncidentId,
        ['sort' => 'number_asc', 'page_size' => 100, 'page' => 9999]
    );
    $maximumQueryDuration = max($maximumQueryDuration, $lastPage['duration']);
    $assert(
        $lastPage['window']['page'] === 100
            && $lastPage['window']['first'] === 9901
            && $lastPage['window']['last'] === 10000
            && count($lastPage['rows']) === 100,
        'out-of-range pagination was not clamped to the exact last page'
    );

    $priority = $runList(
        $connection,
        $targetIncidentId,
        ['sort' => 'priority_newest', 'page_size' => 100]
    );
    $maximumQueryDuration = max($maximumQueryDuration, $priority['duration']);
    $previous = null;
    foreach ($priority['rows'] as $row) {
        $current = [
            estab_message_priority_rank((string) $row['09_vorrangstufe']),
            (string) $row['12_abfzeit'],
            (int) $row['00_lfd'],
        ];
        if (is_array($previous)) {
            $ordered = $previous[0] > $current[0]
                || (
                    $previous[0] === $current[0]
                    && (
                        $previous[1] > $current[1]
                        || (
                            $previous[1] === $current[1]
                            && $previous[2] > $current[2]
                        )
                    )
                );
            $assert($ordered, 'priority/newest sort is not stable');
        }
        $previous = $current;
    }

    $statusPlan = $runList(
        $connection,
        $targetIncidentId,
        [
            'status' => 'done',
            'from' => '2026-01-03',
            'sort' => 'newest',
            'page_size' => 25,
        ]
    );
    $statusKeys = $explainKeys(
        $connection,
        $statusPlan['sql'],
        $statusPlan['params']
    );
    $assert(
        in_array('idx_nachrichten_einsatz_status_zeit', $statusKeys, true),
        'EXPLAIN did not use the incident/status/time index: '
            . implode(', ', $statusKeys)
    );

    $directionPlan = $runList(
        $connection,
        $targetIncidentId,
        [
            'direction' => 'E',
            'sort' => 'number_asc',
            'page_size' => 25,
        ]
    );
    $directionKeys = $explainKeys(
        $connection,
        $directionPlan['sql'],
        $directionPlan['params']
    );
    $assert(
        array_intersect(
            [
                'idx_nachrichten_einsatz_richtung_nummer',
                'idx_nachrichten_richtung_nummer',
            ],
            $directionKeys
        ) !== [],
        'EXPLAIN did not use an indexed direction/number page path: '
            . implode(', ', $directionKeys)
    );
    $directionCountFilter = estab_message_list_filter_sql(
        array_replace(
            estab_message_list_default_filters(),
            ['direction' => 'E', 'sort' => 'number_asc', 'page_size' => 25]
        ),
        'm'
    );
    $directionCountKeys = $explainKeys(
        $connection,
        'SELECT COUNT(*) FROM `nv_nachrichten` AS m'
            . ' WHERE m.`einsatz_id` = ? AND '
            . $directionCountFilter['sql'],
        array_merge([$targetIncidentId], $directionCountFilter['params'])
    );
    $assert(
        in_array(
            'idx_nachrichten_einsatz_richtung_nummer',
            $directionCountKeys,
            true
        ),
        'EXPLAIN did not use the incident/direction/number index for the '
            . 'prepared production count path: '
            . implode(', ', $directionCountKeys)
    );

    $fulltextKeys = $explainKeys(
        $connection,
        $fulltext['sql'],
        $fulltext['params']
    );
    $assert(
        in_array('ft_nachrichten_suche', $fulltextKeys, true),
        'EXPLAIN did not use the canonical full-text index: '
            . implode(', ', $fulltextKeys)
    );

    $benchmarkStarted = hrtime(true);
    $benchmarkCases = [
        [
            'status' => 'done',
            'from' => '2026-01-03',
            'sort' => 'newest',
            'page_size' => 50,
        ],
        [
            'direction' => 'E',
            'sort' => 'number_asc',
            'page_size' => 50,
            'page' => 40,
        ],
        [
            'q' => 'Sondermarker Nord',
            'sort' => 'number_desc',
            'page_size' => 50,
        ],
    ];
    for ($round = 0; $round < 5; $round++) {
        foreach ($benchmarkCases as $filters) {
            $probe = $runList($connection, $targetIncidentId, $filters);
            $maximumQueryDuration = max(
                $maximumQueryDuration,
                $probe['duration']
            );
            $assert(
                $probe['count'] > 0 && $probe['rows'] !== [],
                'representative scale benchmark unexpectedly returned no rows'
            );
        }
    }
    $benchmarkDuration = (hrtime(true) - $benchmarkStarted) / 1_000_000_000;
    $assert(
        $maximumQueryDuration <= ESTAB_MESSAGE_LIST_SCALE_QUERY_SECONDS,
        sprintf(
            'one prepared count/page pair exceeded the %.1f-second guard (%.3fs)',
            ESTAB_MESSAGE_LIST_SCALE_QUERY_SECONDS,
            $maximumQueryDuration
        )
    );
    $assert(
        $benchmarkDuration <= ESTAB_MESSAGE_LIST_SCALE_BENCHMARK_SECONDS,
        sprintf(
            '15-query scale benchmark exceeded the %.1f-second guard (%.3fs)',
            ESTAB_MESSAGE_LIST_SCALE_BENCHMARK_SECONDS,
            $benchmarkDuration
        )
    );

    printf(
        "Message-list scale integration: OK (%d assertions; %d target + %d foreign rows; seed %.3fs; slowest count/page %.3fs; 15-query benchmark %.3fs)\n",
        $assertions,
        ESTAB_MESSAGE_LIST_SCALE_TARGET_ROWS,
        ESTAB_MESSAGE_LIST_SCALE_FOREIGN_ROWS,
        $seedDuration,
        $maximumQueryDuration,
        $benchmarkDuration
    );
} finally {
    $connection->close();
}
