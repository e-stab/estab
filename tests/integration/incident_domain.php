<?php

declare(strict_types=1);

if (getenv('ESTAB_INCIDENT_INTEGRATION') !== '1') {
    fwrite(STDERR, "ESTAB_INCIDENT_INTEGRATION=1 is required\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/app/incident.php';
require_once dirname(__DIR__, 2) . '/app/readiness.php';

$databaseName = getenv('ESTAB_DB_NAME') ?: '';
if (
    preg_match('/\Aestab_incident_[a-z0-9_]*test\z/D', $databaseName) !== 1
) {
    fwrite(STDERR, "Refusing to run incident integration test outside an isolated test database\n");
    exit(2);
}
$passwordFile = getenv('ESTAB_DB_ROOT_PASSWORD_FILE') ?: '';
$password = is_readable($passwordFile)
    ? trim((string) file_get_contents($passwordFile))
    : '';
if ($password === '') {
    fwrite(STDERR, "Readable test database password is required\n");
    exit(2);
}

$databaseConfig = [
    'server' => getenv('ESTAB_DB_HOST') ?: 'db',
    'user' => 'root',
    'password' => $password,
    'datenbank' => $databaseName,
];
unset($password);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$fails = static function (callable $operation): ?Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    return null;
};
$queryValue = static function (mysqli $connection, string $sql): mixed {
    $result = $connection->query($sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Integration query returned no result');
    }
    try {
        $row = $result->fetch_row();
    } finally {
        $result->free();
    }
    return is_array($row) ? ($row[0] ?? null) : null;
};

$connection = estab_auth_connect($databaseConfig);
// The legacy connection adapter deliberately disables mysqli exceptions for
// historical call sites. This integration test enables them again so exact
// trigger failures can be asserted without reading mutable global errno state.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$contender = null;
try {
    $assert(
        (int) $queryValue(
            $connection,
            estab_readiness_schema_query()
        ) === 1,
        'PHP readiness query disagrees with the post-migration verifier'
    );
    $status = estab_incident_status($connection);
    $assert(
        $status['active_einsatz_id'] === null && $status['revision'] === 0,
        'fresh migrated database did not start with a locked inactive status'
    );
    $assert(
        $fails(
            static fn (): array => estab_incident_require_active($connection)
        ) instanceof EstabNoActiveIncidentException,
        'no-active PHP input gate did not fail closed'
    );

    $blockedInsert = $fails(
        static fn (): bool => $connection->query(
            "INSERT INTO `nv_nachrichten` (`12_inhalt`) VALUES ('blocked')"
        )
    );
    $assert(
        $blockedInsert instanceof mysqli_sql_exception
            && (int) $blockedInsert->getCode() === 1644,
        'database accepted an operational insert without an active incident'
    );

    $incidentA = estab_incident_create(
        $connection,
        [
            'kennung' => 'TEST-A-001',
            'name' => 'Integration A',
            'beginn' => date('Y-m-d\TH:i', time() - 60),
            'ort' => 'Testort A',
        ],
        'integration-test',
        false
    );
    $incidentB = estab_incident_create(
        $connection,
        [
            'kennung' => 'TEST-B-001',
            'name' => 'Integration B',
            'beginn' => date('Y-m-d\TH:i', time() - 60),
            'ort' => 'Testort B',
        ],
        'integration-test',
        false
    );
    $idA = (int) $incidentA['einsatz_id'];
    $idB = (int) $incidentB['einsatz_id'];
    $assert($idA > 0 && $idB > $idA, 'incident creation returned invalid IDs');
    $assert(
        $fails(
            static fn (): array => estab_incident_create(
                $connection,
                [
                    'kennung' => 'TEST-A-001',
                    'name' => 'Duplicate A',
                    'beginn' => date('Y-m-d\TH:i', time() - 60),
                ],
                'integration-test',
                false
            )
        ) instanceof EstabIncidentConflictException,
        'duplicate stable incident identifier was not reported as a conflict'
    );

    $status = estab_incident_activate(
        $connection,
        $idA,
        0,
        'integration-test'
    );
    $assert(
        $status['active_einsatz_id'] === $idA && $status['revision'] === 1,
        'first incident activation did not advance the singleton revision'
    );
    $assert(
        $fails(
            static fn (): array => estab_incident_activate(
                $connection,
                $idB,
                0,
                'stale-integration-test'
            )
        ) instanceof EstabIncidentConflictException,
        'stale activation replaced a newer operator decision'
    );

    $connection->query(
        "INSERT INTO `nv_nachrichten` (`12_inhalt`) VALUES ('incident A')"
    );
    $messageA = (int) $connection->insert_id;
    $assert(
        (int) $queryValue(
            $connection,
            'SELECT `einsatz_id` FROM `nv_nachrichten`'
                . ' WHERE `00_lfd` = ' . $messageA
        ) === $idA,
        'legacy insert was not assigned to the active incident'
    );
    $assert(
        $fails(
            static fn (): bool => $connection->query(
                'INSERT INTO `nv_nachrichten` (`einsatz_id`, `12_inhalt`)'
                . ' VALUES (' . $idB . ", 'wrong incident')"
            )
        ) instanceof mysqli_sql_exception,
        'explicit insert into an inactive incident was accepted'
    );
    $assert(
        $fails(
            static fn (): bool => $connection->query(
                'UPDATE `nv_nachrichten` SET `einsatz_id` = ' . $idB
                . ' WHERE `00_lfd` = ' . $messageA
            )
        ) instanceof mysqli_sql_exception,
        'incident reassignment was accepted'
    );

    $writtenId = estab_incident_with_active_write(
        $connection,
        static function (array $active) use ($connection): int {
            $statement = $connection->prepare(
                'INSERT INTO `nv_nachrichten` (`einsatz_id`, `12_inhalt`)'
                . ' VALUES (?, ?)'
            );
            if (!$statement) {
                throw new RuntimeException('Could not prepare active write');
            }
            try {
                $id = (int) $active['active_einsatz_id'];
                $body = 'transaction-bound A';
                $statement->bind_param('is', $id, $body);
                $statement->execute();
                return (int) $connection->insert_id;
            } finally {
                $statement->close();
            }
        }
    );
    $assert($writtenId > $messageA, 'active write transaction returned no row');

    $status = estab_incident_deactivate(
        $connection,
        $idA,
        1,
        'integration-test'
    );
    $assert(
        $status['active_einsatz_id'] === null && $status['revision'] === 2,
        'deactivation did not clear and revise the singleton'
    );
    foreach ([
        static fn (): bool => $connection->query(
            "INSERT INTO `nv_nachrichten` (`12_inhalt`) VALUES ('blocked again')"
        ),
        static fn (): bool => $connection->query(
            "UPDATE `nv_nachrichten` SET `12_inhalt` = 'blocked'"
            . ' WHERE `00_lfd` = ' . $messageA
        ),
        static fn (): bool => $connection->query(
            'DELETE FROM `nv_nachrichten` WHERE `00_lfd` = ' . $messageA
        ),
    ] as $blockedOperation) {
        $assert(
            $fails($blockedOperation) instanceof mysqli_sql_exception,
            'operational mutation succeeded while no incident was active'
        );
    }

    $connection->query(
        "INSERT INTO `nv_protokoll` (`p_was`, `p_ereignis`)"
        . " VALUES ('LOGIN', 'global event without active incident')"
    );
    $globalProtocolOne = (int) $connection->insert_id;
    $assert(
        $queryValue(
            $connection,
            'SELECT `einsatz_id` FROM `nv_protokoll`'
                . ' WHERE `p_lfd` = ' . $globalProtocolOne
        ) === null,
        'global protocol event was forced into an incident'
    );

    $status = estab_incident_activate(
        $connection,
        $idB,
        2,
        'integration-test'
    );
    $assert(
        $status['active_einsatz_id'] === $idB && $status['revision'] === 3,
        'second incident activation failed'
    );
    $assert(
        $fails(
            static fn (): bool => $connection->query(
                "UPDATE `nv_nachrichten` SET `12_inhalt` = 'wrong incident'"
                . ' WHERE `00_lfd` = ' . $messageA
            )
        ) instanceof mysqli_sql_exception,
        'historical incident row was writable under another active incident'
    );

    $connection->query(
        "INSERT INTO `nv_nachrichten` (`12_inhalt`) VALUES ('incident B')"
    );
    $messageB = (int) $connection->insert_id;
    $assert(
        (int) $queryValue(
            $connection,
            'SELECT `einsatz_id` FROM `nv_nachrichten`'
                . ' WHERE `00_lfd` = ' . $messageB
        ) === $idB,
        'second active incident did not receive its own row'
    );

    $connection->query(
        "INSERT INTO `nv_protokoll` (`p_was`, `p_ereignis`)"
        . " VALUES ('ADMIN', 'global event while incident active')"
    );
    $globalProtocolTwo = (int) $connection->insert_id;
    $statement = $connection->prepare(
        'INSERT INTO `nv_protokoll` (`einsatz_id`, `p_was`, `p_ereignis`)'
        . ' VALUES (?, ?, ?)'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare operational protocol event');
    }
    try {
        $protocolAction = 'FM-Eingang';
        $protocolDetail = 'operational event';
        $statement->bind_param('iss', $idB, $protocolAction, $protocolDetail);
        $statement->execute();
        $operationalProtocol = (int) $connection->insert_id;
    } finally {
        $statement->close();
    }
    $assert(
        $queryValue(
            $connection,
            'SELECT `einsatz_id` FROM `nv_protokoll`'
                . ' WHERE `p_lfd` = ' . $globalProtocolTwo
        ) === null
            && (int) $queryValue(
                $connection,
                'SELECT `einsatz_id` FROM `nv_protokoll`'
                    . ' WHERE `p_lfd` = ' . $operationalProtocol
            ) === $idB,
        'global and operational protocol contexts were not kept distinct'
    );

    $found = estab_incident_find($connection, $idB);
    $listed = estab_incident_list($connection);
    $assert(
        is_array($found)
            && $found['ist_aktiv'] === true
            && $found['kennung'] === 'TEST-B-001'
            && count($listed) === 2,
        'incident find/list reader lost active or archive state'
    );

    $connection->begin_transaction();
    $locked = $connection->query(
        'SELECT `revision` FROM `nv_einsatz_status`'
        . ' WHERE `singleton_id` = 1 FOR UPDATE'
    );
    if ($locked instanceof mysqli_result) {
        $locked->free();
    }
    $contender = estab_auth_connect($databaseConfig);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $contender->query('SET SESSION innodb_lock_wait_timeout = 1');
    $lockFailure = $fails(
        static fn (): array => estab_incident_activate(
            $contender,
            $idA,
            3,
            'concurrent-integration-test'
        )
    );
    $assert(
        $lockFailure instanceof mysqli_sql_exception
            && (int) $lockFailure->getCode() === 1205,
        'concurrent activation bypassed the locked singleton'
    );
    $connection->rollback();

    $status = estab_incident_activate(
        $contender,
        $idA,
        3,
        'concurrent-integration-test'
    );
    $assert(
        $status['active_einsatz_id'] === $idA && $status['revision'] === 4,
        'serialized activation did not succeed after the lock was released'
    );
} finally {
    if ($connection->errno === 0) {
        // Harmless when no transaction is open; keeps an interrupted lock
        // probe from surviving until connection close.
        @$connection->rollback();
    }
    if ($contender instanceof mysqli) {
        estab_auth_close($contender);
    }
    estab_auth_close($connection);
}

echo "incident domain integration: OK ({$assertions} assertions)\n";
