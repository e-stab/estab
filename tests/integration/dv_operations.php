<?php

declare(strict_types=1);

if (getenv('ESTAB_DV_OPERATIONS_INTEGRATION') !== '1') {
    fwrite(STDERR, "ESTAB_DV_OPERATIONS_INTEGRATION=1 is required\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/app/dv_operations.php';
require_once dirname(__DIR__, 2) . '/app/logbook.php';
require_once dirname(__DIR__, 2) . '/app/attachment.php';
require_once dirname(__DIR__, 2) . '/app/category.php';
require_once dirname(__DIR__, 2) . '/app/message_evidence.php';

$databaseName = getenv('ESTAB_DB_NAME') ?: '';
if (preg_match('/\Aestab_dv_operations_[a-z0-9_]*\z/D', $databaseName) !== 1) {
    fwrite(
        STDERR,
        "Refusing to run DV operations integration outside an isolated database\n"
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
    fwrite(STDERR, "DV operations integration database password is required\n");
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
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (
    string $expectedClass,
    callable $operation,
    string $message
) use ($assert): Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        $assert(
            $exception instanceof $expectedClass,
            $message . ' (got ' . $exception::class . ')'
        );
        return $exception;
    }
    $assert(false, $message . ' (no exception)');
    throw new LogicException('unreachable');
};
$scalar = static function (
    mysqli $connection,
    string $sql,
    string $types = '',
    mixed ...$parameters
): mixed {
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare DV integration scalar');
    }
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        $statement->execute();
        $row = $statement->get_result()->fetch_row();
        return is_array($row) ? ($row[0] ?? null) : null;
    } finally {
        $statement->close();
    }
};
$logbookEvidenceSnapshot = static function (
    mysqli $connection,
    int $incidentId
) use ($scalar): array {
    return [
        'etb' => (string) $scalar(
            $connection,
            "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(`estab_book_lfd`), 0))"
                . ' FROM `nv_etb` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'ttb' => (string) $scalar(
            $connection,
            "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(`estab_book_lfd`), 0))"
                . ' FROM `nv_tbb` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'heads' => (string) $scalar(
            $connection,
            "SELECT COALESCE(GROUP_CONCAT(CONCAT(`buchart`, ':', `next_lfd`)"
                . " ORDER BY `buchart` SEPARATOR ','), '')"
                . ' FROM `nv_logbuch_koepfe` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'dv_events' => (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_betriebsereignisse`'
                . ' WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'dv_event_head' => (string) ($scalar(
            $connection,
            "SELECT CONCAT(`letzte_sequenz`, ':', `letzter_hash`)"
                . ' FROM `nv_betriebsereignis_kopf` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ) ?? ''),
        'incident_events' => (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_einsatz_ereignisse`'
                . ' WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'protocol' => (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_protokoll` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
    ];
};
$handoverStateSnapshot = static function (
    mysqli $connection,
    int $incidentId,
    int $requestId
) use ($scalar): array {
    return [
        'shifts' => (string) $scalar(
            $connection,
            "SELECT COALESCE(GROUP_CONCAT(CONCAT(`dienstschicht_id`, ':',"
                . " `status`, ':', COALESCE(CAST(`aktiviert_am` AS CHAR), ''),"
                . " ':', COALESCE(CAST(`beendet_am` AS CHAR), ''))"
                . " ORDER BY `dienstschicht_id` SEPARATOR ','), '')"
                . ' FROM `nv_dienstschichten` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'request' => (string) ($scalar(
            $connection,
            "SELECT CONCAT(`status`, ':',"
                . " COALESCE(CAST(`bestaetigt_am` AS CHAR), ''), ':',"
                . " COALESCE(`bestaetigt_von`, ''), ':',"
                . " COALESCE(`bestaetigt_mit_besetzung_id`, 0), ':',"
                . " COALESCE(`dienstuebergabe_id`, 0))"
                . ' FROM `nv_dienstuebergabe_anfragen`'
                . ' WHERE `dienstuebergabe_anfrage_id` = ?',
            'i',
            $requestId
        ) ?? ''),
        'handovers' => (string) $scalar(
            $connection,
            "SELECT CONCAT(COUNT(*), ':',"
                . ' COALESCE(MAX(`dienstuebergabe_id`), 0))'
                . ' FROM `nv_dienstuebergaben` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ),
        'assignments' => (string) $scalar(
            $connection,
            "SELECT COALESCE(GROUP_CONCAT(CONCAT("
                . " assignment.`dienstbesetzung_id`, ':', assignment.`status`,"
                . " ':', COALESCE(CAST(assignment.`abgeloest_am` AS CHAR), ''),"
                . " ':', COALESCE(assignment.`nachfolger_id`, 0))"
                . " ORDER BY assignment.`dienstbesetzung_id` SEPARATOR ','), '')"
                . ' FROM `nv_dienstbesetzungen` AS assignment'
                . ' JOIN `nv_dienstschichten` AS shift_row'
                . ' ON shift_row.`dienstschicht_id` ='
                . ' assignment.`dienstschicht_id`'
                . ' WHERE shift_row.`einsatz_id` = ?',
            'i',
            $incidentId
        ),
    ];
};

$suffix = substr(bin2hex(random_bytes(4)), 0, 4);
$codes = [
    's2' => 'a' . $suffix,
    'si' => 'b' . $suffix,
    'si_duplicate' => 'c' . $suffix,
    'ldf' => 'd' . $suffix,
    'ldf_duplicate' => 'e' . $suffix,
    'aw' => 'f' . $suffix,
    'messenger' => 'g' . $suffix,
    's6' => 'h' . $suffix,
    's2_successor' => 'i' . $suffix,
    's1_extension' => 'j' . $suffix,
    'aw_extension' => 'k' . $suffix,
    's3' => 'l' . $suffix,
    'etb' => 'm' . $suffix,
];
$actor = 'dv-operations-integration';
$assignments = [];
$secondAssignments = [];
$incidentId = 0;
$messageId = 0;
$conversationMessageId = 0;
$sessionStarted = false;

$phpSessionId = 'dvops' . $suffix;
if (session_status() !== PHP_SESSION_NONE) {
    throw new RuntimeException('DV operations integration session already started');
}
session_id($phpSessionId);
if (!session_start()) {
    throw new RuntimeException('Could not start DV operations integration session');
}
$phpSessionId = session_id();
if (!estab_auth_session_id_is_valid($phpSessionId)) {
    throw new RuntimeException('PHP generated an invalid integration session id');
}
$sessionStarted = true;

try {
    $status = estab_incident_status($connection);
    $assert(
        $status['active_einsatz_id'] === null,
        'isolated operations database unexpectedly has an active incident'
    );
    $created = estab_incident_create(
        $connection,
        [
            'kennung' => 'DV-OPS-' . strtoupper($suffix),
            'name' => 'DV 1-101 Führungsstellenbetrieb',
            'beginn' => date('Y-m-d\TH:i', time() - 3600),
            'ort' => 'Integrationsprüfung',
            'organisation' => 'THW',
            'fuehrungsstellenname' => 'Führungsstelle DV-Operationen',
            'einsatzleitung' => 'Leitung Integration',
            'beschreibung' => 'Isolierter Nachweis der DV-Abläufe.',
        ],
        $actor,
        true,
        (int) $status['revision']
    );
    $incidentId = (int) $created['einsatz_id'];
    $assert($incidentId > 0, 'active DV integration incident was not created');
    $preShiftS2Identity = [
        'benutzer' => 'Lage/Dokumentation',
        'kuerzel' => $codes['s2'],
        'funktion' => 'S2',
        'rolle' => 'Stab',
    ];
    $preShiftAwIdentity = [
        'benutzer' => 'Aufnahme/Weitergabe',
        'kuerzel' => $codes['aw'],
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
    ];
    $insertUser = $connection->prepare(
        'INSERT INTO `nv_benutzer`'
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `aktiv`,'
        . ' `estab_letzte_aktivitaet`, `estab_gesperrt`, `password`)'
        . ' VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(6), 0, ?)'
    );
    if (!$insertUser) {
        throw new RuntimeException('Could not prepare DV integration accounts');
    }
    try {
        $users = [
            [$codes['s2'], 'S2', 'Stab', 'Lage/Dokumentation'],
            [$codes['si'], 'Si', 'Stab', 'Sichter'],
            [$codes['si_duplicate'], 'Si', 'Stab', 'Zweiter Sichter'],
            [$codes['ldf'], 'LdF', 'Fernmelder', 'Leiter FmZt'],
            [
                $codes['ldf_duplicate'],
                'LdF',
                'Fernmelder',
                'Zweiter Leiter FmZt',
            ],
            [$codes['aw'], 'A/W', 'Fernmelder', 'Aufnahme/Weitergabe'],
            [$codes['messenger'], 'A/W', 'Fernmelder', 'Melder'],
            [$codes['s6'], 'S6', 'Stab', 'Fernmeldeplanung'],
            [
                $codes['s2_successor'],
                'S2',
                'Stab',
                'Nachfolgende Lage/Dokumentation',
            ],
            [$codes['s1_extension'], 'S1', 'Stab', 'Schichterweiterung S1'],
            [
                $codes['aw_extension'],
                'A/W',
                'Fernmelder',
                'Zusätzliche Aufnahme/Weitergabe',
            ],
            [$codes['s3'], 'S3', 'Stab', 'Sachgebiet 3'],
            [$codes['etb'], 'ETB', 'Stab', 'Einsatztagebuchführung'],
        ];
        foreach ($users as [$code, $function, $role, $name]) {
            $sessionId = hash_equals($code, $codes['s2'])
                ? $phpSessionId
                : 'dv-session-' . $suffix . '-' . $code;
            $passwordHash = password_hash(
                'DV integration account ' . $code,
                PASSWORD_DEFAULT
            );
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Could not hash integration password');
            }
            $insertUser->bind_param(
                'ssssss',
                $name,
                $code,
                $function,
                $role,
                $sessionId,
                $passwordHash
            );
            $insertUser->execute();
        }
    } finally {
        $insertUser->close();
    }

    $assert(
        estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $preShiftS2Identity
        )['funktion'] === 'S2'
            && estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $preShiftAwIdentity
            )['funktion'] === 'A/W',
        'fixed accounts required an active legacy duty shift'
    );

    $shift = estab_dv_create_shift(
        $connection,
        $incidentId,
        'Dienstschicht 1',
        null,
        $actor
    );
    $shiftId = (int) $shift['dienstschicht_id'];
    $hatDefinitions = [
        's2' => 'S2',
        'si' => 'Si',
        'ldf' => 'LdF',
        'aw' => 'A/W',
        'messenger' => 'A/W',
        's6' => 'S6',
    ];
    foreach ($hatDefinitions as $accountKey => $function) {
        $assignment = estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes[$accountKey],
            $function,
            $actor
        );
        $assignments[$accountKey] = (int) $assignment['dienstbesetzung_id'];
    }
    $s3Assignment = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['s3'],
        'S3',
        $actor
    );
    $assignments['s3'] = (int) $s3Assignment['dienstbesetzung_id'];
    $etbAssignment = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['etb'],
        'ETB',
        $actor
    );
    $assignments['etb'] = (int) $etbAssignment['dienstbesetzung_id'];
    $assert(
        count($assignments) === 8,
        'initial and combined function hats were not assigned'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes['si_duplicate'],
            'Si',
            $actor
        ),
        'a second active Sichter was accepted in one shift'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes['ldf_duplicate'],
            'LdF',
            $actor
        ),
        'a second active LdF was accepted in one shift'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_dienstbesetzungen`'
                . ' WHERE `dienstschicht_id` = ?'
                . " AND `funktion` = 'A/W'",
            'i',
            $shiftId
        ) === 2,
        'multiple distinct A/W operators were not accepted'
    );

    foreach ($hatDefinitions as $accountKey => $_function) {
        if ($accountKey === 's6') {
            continue;
        }
        estab_dv_accept_hat(
            $connection,
            $incidentId,
            $assignments[$accountKey],
            $codes[$accountKey]
        );
    }
    estab_dv_accept_hat(
        $connection,
        $incidentId,
        $assignments['s3'],
        $codes['s3']
    );
    estab_dv_accept_hat(
        $connection,
        $incidentId,
        $assignments['etb'],
        $codes['etb']
    );
    $assert(
        estab_dv_shift_required_hats($connection, $shiftId) === ['S6'],
        'a shift without accepted S6 was not held before activation'
    );
    estab_dv_accept_hat(
        $connection,
        $incidentId,
        $assignments['s6'],
        $codes['s6']
    );
    $unacceptedOutgoing = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['ldf_duplicate'],
        'A/W',
        $actor
    );
    $unacceptedOutgoingAssignmentId =
        (int) $unacceptedOutgoing['dienstbesetzung_id'];
    $assert(
        estab_dv_shift_required_hats($connection, $shiftId) === [],
        'accepted initial shift still reports missing mandatory functions'
    );

    // Force the final audit write to fail after historical shift activation.
    // The incident activation already opened both books independently of any
    // shift, so the surrounding transaction must leave their evidence intact.
    $openingRollbackEvidence = $logbookEvidenceSnapshot(
        $connection,
        $incidentId
    );
    $openingRollbackShift = (string) $scalar(
        $connection,
        "SELECT CONCAT(`status`, '|', COALESCE(CAST(`aktiviert_am` AS CHAR), ''))"
            . ' FROM `nv_dienstschichten` WHERE `dienstschicht_id` = ?',
        'i',
        $shiftId
    );
    $expect(
        RuntimeException::class,
        static fn (): null => (
            estab_dv_activate_initial_shift(
                $connection,
                $incidentId,
                $shiftId,
                $actor,
                'estab_test_missing_protocol'
            ) ?? null
        ),
        'an injected opening audit failure did not abort activation'
    );
    $assert(
        $logbookEvidenceSnapshot($connection, $incidentId)
            === $openingRollbackEvidence
            && (string) $scalar(
                $connection,
                "SELECT CONCAT(`status`, '|',"
                    . " COALESCE(CAST(`aktiviert_am` AS CHAR), ''))"
                    . ' FROM `nv_dienstschichten`'
                    . ' WHERE `dienstschicht_id` = ?',
                'i',
                $shiftId
            ) === $openingRollbackShift
            && $openingRollbackShift === 'GEPLANT|',
        'failed historical shift activation retained a partial shift or '
            . 'changed an ETB/TBB row, head '
            . 'or audit event'
    );
    estab_dv_activate_initial_shift(
        $connection,
        $incidentId,
        $shiftId,
        $actor
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?'
                . ' AND `estab_book_lfd` = 1'
                . " AND `estab_event_type` = 'ohne'"
                . " AND `etb_aktion` LIKE '%Einsatztagebuch eröffnet%'"
                . " AND `etb_aktion` LIKE '%Einsatzbeginn:%'"
                . " AND `etb_aktion` NOT LIKE '%Zweiter Leiter FmZt%'",
            'i',
            $incidentId
        ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_book_lfd` = 1'
                    . " AND `estab_entry_type` = 'betrieb_personal'"
                    . " AND `estab_personnel_duty` LIKE '%Betriebsaufnahme%'",
                'i',
                $incidentId
            ) === 1,
        'incident activation did not open ETB and TBB at local number 1'
    );
    $activeAssignmentCount = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_dienstbesetzungen`'
            . ' WHERE `dienstschicht_id` = ?',
        'i',
        $shiftId
    );
    $activeEtbAssignmentRejection = $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes['si_duplicate'],
            'ETB',
            $actor
        ),
        'active shift accepted an ETB assignment that would replace its writer'
    );
    $assert(
        str_contains(
            $activeEtbAssignmentRejection->getMessage(),
            'dokumentierte und bestätigte Schichtübergabe'
        )
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_dienstbesetzungen`'
                    . ' WHERE `dienstschicht_id` = ?',
                'i',
                $shiftId
            ) === $activeAssignmentCount,
        'rejected active-shift ETB assignment was unclear or mutated the roster'
    );

    // A running shift may be expanded without pretending that personnel were
    // present in opening row 1. Assignment alone is not yet effective; the
    // person's acceptance appends immutable book evidence in the same
    // transaction. A non-Fm function belongs only in the ETB.
    $s1Extension = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['s1_extension'],
        'S1',
        $actor
    );
    $s1ExtensionId = (int) $s1Extension['dienstbesetzung_id'];
    $assert(
        ($s1Extension['active_shift_extension'] ?? null) === true
            && ($s1Extension['schicht_status'] ?? null) === 'AKTIV',
        'active-shift S1 extension was not identified as an extension'
    );
    $s1ExtensionBefore = $logbookEvidenceSnapshot($connection, $incidentId);
    $expect(
        RuntimeException::class,
        static fn (): array => estab_dv_accept_hat(
            $connection,
            $incidentId,
            $s1ExtensionId,
            $codes['s1_extension'],
            'estab_test_missing_protocol'
        ),
        'an injected extension-audit failure did not abort acceptance'
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT `status` FROM `nv_dienstbesetzungen`'
                . ' WHERE `dienstbesetzung_id` = ?',
            'i',
            $s1ExtensionId
        ) === 'ZUGEWIESEN'
            && $logbookEvidenceSnapshot($connection, $incidentId)
                === $s1ExtensionBefore,
        'failed active-shift acceptance retained assignment, ETB/TBB, head '
            . 'or audit mutations'
    );
    $acceptedS1Extension = estab_dv_accept_hat(
        $connection,
        $incidentId,
        $s1ExtensionId,
        $codes['s1_extension']
    );
    $assert(
        ($acceptedS1Extension['active_shift_extension'] ?? null) === true
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?'
                    . " AND `etb_aktion` LIKE '%Schichtbesetzung erweitert%'"
                    . " AND `etb_aktion` LIKE '%Schichterweiterung S1%'",
                'i',
                $incidentId
            ) === 1
            && (string) $scalar(
                $connection,
                "SELECT CONCAT(COUNT(*), ':',"
                    . " COALESCE(MAX(`estab_book_lfd`), 0)) FROM `nv_tbb`"
                    . ' WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === $s1ExtensionBefore['ttb'],
        'accepted S1 extension was not written exactly once to the ETB only'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes['si_duplicate'],
            'S1',
            $actor
        ),
        'an occupied non-A/W function was replaced inside the active shift'
    );

    // A/W is deliberately multi-seat. Additional operating personnel is a
    // genuine extension and must appear in both ETB and TBB.
    $awExtension = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['aw_extension'],
        'A/W',
        $actor
    );
    $awExtensionId = (int) $awExtension['dienstbesetzung_id'];
    $awExtensionBefore = $logbookEvidenceSnapshot($connection, $incidentId);
    $acceptedAwExtension = estab_dv_accept_hat(
        $connection,
        $incidentId,
        $awExtensionId,
        $codes['aw_extension']
    );
    $assert(
        ($acceptedAwExtension['active_shift_extension'] ?? null) === true
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?'
                    . " AND `etb_aktion` LIKE '%Zusätzliche Aufnahme/Weitergabe%'",
                'i',
                $incidentId
            ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?'
                    . " AND `estab_entry_type` = 'betrieb_personal'"
                    . " AND `estab_personnel_duty`"
                    . " LIKE '%Zusätzliche Aufnahme/Weitergabe%'",
                'i',
                $incidentId
            ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === ((int) explode(':', $awExtensionBefore['etb'], 2)[0]) + 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === ((int) explode(':', $awExtensionBefore['ttb'], 2)[0]) + 1,
        'accepted active A/W extension was not appended atomically to ETB and TBB'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $codes['aw_extension'],
            'A/W',
            $actor
        ),
        'the same person received the same active A/W function twice'
    );

    $connection->begin_transaction();
    try {
        $expect(
            LogicException::class,
            static fn (): null => (
                estab_dynamic_schema_reconcile_hat(
                    $connection,
                    'usr_',
                    'S3',
                    $codes['s2'],
                    'Stab'
                ) ?? null
            ),
            'dynamic function DDL was allowed inside a domain transaction'
        );
        $assert(
            (int) $scalar(
                $connection,
                'SELECT @@SESSION.in_transaction'
            ) === 1,
            'rejected dynamic DDL implicitly ended the caller transaction'
        );
    } finally {
        $connection->rollback();
    }

    $s3Identity = [
        'benutzer' => 'Sachgebiet 3',
        'kuerzel' => $codes['s3'],
        'funktion' => 'S3',
        'rolle' => 'Stab',
    ];
    $assert(
        estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $s3Identity
        )['funktion'] === 'S3',
        'fixed S3 account was not accepted at the operational boundary'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            [
                'benutzer' => 'Lage/Dokumentation',
                'kuerzel' => $codes['s2'],
                'funktion' => 'S3',
                'rolle' => 'Stab',
            ]
        ),
        'an S2 account changed function through historical S3 staffing'
    );

    $s3ReadTable = estab_message_state_table(
        'usr_',
        'S3',
        $codes['s3'],
        'read'
    );
    $s3DoneTable = estab_message_state_table(
        'usr_',
        'S3',
        $codes['s3'],
        'done'
    );
    $s3FunctionCategory = 'usr__fkt_s3_katego';
    $s3FunctionLink = 'usr__fkt_s3_kategolink';
    $s3UserCategory = 'usr_s3_' . $codes['s3'] . '_katego';
    $s3UserLink = 'usr_s3_' . $codes['s3'] . '_kategolink';
    $expectedS3Tables = [
        $s3ReadTable,
        $s3DoneTable,
        $s3FunctionCategory,
        $s3FunctionLink,
        $s3UserCategory,
        $s3UserLink,
    ];
    $tablePlaceholders = implode(
        ',',
        array_fill(0, count($expectedS3Tables), '?')
    );
    $tableTypes = str_repeat('s', count($expectedS3Tables));
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE()'
                . ' AND table_type = ? AND table_name IN ('
                . $tablePlaceholders . ')',
            's' . $tableTypes,
            'BASE TABLE',
            ...$expectedS3Tables
        ) === 6,
        'accepted S3 hat did not receive all six legacy state/category tables'
    );

    $conversationTtbBefore = (string) $scalar(
        $connection,
        "SELECT CONCAT("
            . "(SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(`estab_book_lfd`), 0))"
            . " FROM `nv_tbb` WHERE `einsatz_id` = ?), '|',"
            . "(SELECT `next_lfd` FROM `nv_logbuch_koepfe`"
            . " WHERE `einsatz_id` = ? AND `buchart` = 'TTB'))",
        'ii',
        $incidentId,
        $incidentId
    );
    $conversation = estab_message_insert_numbered(
        $connection,
        $databaseName,
        'nv_nachrichten',
        'E',
        false,
        [
            '01_medium' => '',
            '01_datum' => date('Y-m-d H:i:s'),
            '01_zeichen' => $codes['s3'],
            '10_anschrift' => 'Führungsstelle Integration',
            '11_gesprnotiz' => 't',
            '12_inhalt' => 'S3-Gesprächsnotiz aus festem S3-Konto',
            '12_abfzeit' => date('Y-m-d H:i:s'),
            '14_zeichen' => $codes['s3'],
            '14_funktion' => 'S3',
            '16_empf' => 'S2_rt,S3_gn',
            'x00_status' => 8,
            'x01_abschluss' => 't',
            'x02_sperre' => 'f',
            'x03_sperruser' => '',
        ],
        'nv_anhang',
        [
            'event_type' => 'conversation_note_created',
            'actor' => $s3Identity,
            'from_status' => null,
            'to_status' => 8,
            'snapshot' => [
                'direction' => 'E',
                'object_type' => 'conversation_note',
                'conversation_note' => true,
                'author_code' => $codes['s3'],
                'author_function' => 'S3',
                'review_required' => false,
            ],
        ]
    );
    $conversationMessageId = (int) $conversation['id'];
    $assert(
        $conversationMessageId > 0
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb`'
                    . ' WHERE `einsatz_id` = ? AND `estab_message_id` = ?',
                'ii',
                $incidentId,
                $conversationMessageId
            ) === 0
            && (string) $scalar(
                $connection,
                "SELECT CONCAT("
                    . "(SELECT CONCAT(COUNT(*), ':',"
                    . " COALESCE(MAX(`estab_book_lfd`), 0)) FROM `nv_tbb`"
                    . " WHERE `einsatz_id` = ?), '|',"
                    . "(SELECT `next_lfd` FROM `nv_logbuch_koepfe`"
                    . " WHERE `einsatz_id` = ? AND `buchart` = 'TTB'))",
                'ii',
                $incidentId,
                $incidentId
            ) === $conversationTtbBefore
            && (estab_message_fetch_for_incident_by_id(
                $connection,
                'nv_nachrichten',
                $conversationMessageId,
                $incidentId
            )['estab_ttb_lfd'] ?? null) === null,
        'conversation note consumed a TTB number or exposed a message TTB reference'
    );
    $stateTimestamp = date('Y-m-d H:i:s');
    $assert(
        estab_message_state_set_for_recipient(
            $connection,
            'nv_nachrichten',
            $s3ReadTable,
            $conversationMessageId,
            'S3',
            'read',
            $stateTimestamp
        ),
        'fixed S3 account could not write its personal read state'
    );
    $assert(
        estab_message_state_set_for_recipient(
            $connection,
            'nv_nachrichten',
            $s3DoneTable,
            $conversationMessageId,
            'S3',
            'done',
            $stateTimestamp
        ),
        'fixed S3 account could not write its function done state'
    );

    $categoryConfig = [
        'usrtblprefix' => 'usr_',
        'masterkatego' => 'nv_masterkatego',
        'masterkategolk' => 'nv_masterkategolink',
    ];
    $functionScope = estab_category_scope(
        'fkt',
        $s3Identity,
        $categoryConfig
    );
    $userScope = estab_category_scope(
        'user',
        $s3Identity,
        $categoryConfig
    );
    $masterScope = estab_category_scope(
        'master',
        $s3Identity,
        $categoryConfig
    );
    $masterCategoryId = estab_category_create(
        $connection,
        $masterScope,
        ['kategorie' => 'M' . $suffix, 'beschreibung' => 'Master-Sperrtest']
    );
    $expect(
        EstabCategoryAuthorizationException::class,
        static fn (): null => (
            estab_category_assign(
                $connection,
                $conversationMessageId,
                'nv_nachrichten',
                $s3Identity,
                ['master' => $masterScope],
                ['master' => $masterCategoryId],
                'nv_empfmtx'
            ) ?? null
        ),
        'composed category API accepted a master assignment without '
            . 'locked Si/redcopy authority'
    );
    $functionCategoryId = estab_category_create(
        $connection,
        $functionScope,
        ['kategorie' => 'F' . $suffix, 'beschreibung' => 'S3-Funktion']
    );
    $userCategoryId = estab_category_create(
        $connection,
        $userScope,
        ['kategorie' => 'U' . $suffix, 'beschreibung' => 'S3-Konto']
    );
    estab_category_assign(
        $connection,
        $conversationMessageId,
        'nv_nachrichten',
        $s3Identity,
        ['fkt' => $functionScope, 'user' => $userScope],
        ['fkt' => $functionCategoryId, 'user' => $userCategoryId]
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM '
                . estab_auth_table($s3FunctionLink)
                . ' WHERE `msg` = ? AND `katego` = ?',
            'ii',
            $conversationMessageId,
            $functionCategoryId
        ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM '
                    . estab_auth_table($s3UserLink)
                    . ' WHERE `msg` = ? AND `katego` = ?',
                'ii',
                $conversationMessageId,
                $userCategoryId
            ) === 1,
        'fixed S3 account could not persist function and user categories'
    );
    $etbIdentity = [
        'benutzer' => 'Einsatztagebuchführung',
        'kuerzel' => $codes['etb'],
        'funktion' => 'ETB',
        'rolle' => 'Stab',
    ];
    $assert(
        estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $etbIdentity
        )['funktion'] === 'ETB',
        'fixed ETB account was not accepted at the operational boundary'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            [
                'benutzer' => 'Sichter',
                'kuerzel' => $codes['si'],
                'funktion' => 'ETB',
                'rolle' => 'Stab',
            ]
        ),
        'a Si account changed function through historical ETB staffing'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE()'
                . ' AND table_name IN (?, ?, ?, ?, ?, ?)',
            'ssssss',
            'usr_etb_' . $codes['etb'] . '_read',
            'usr__fkt_etb_erl',
            'usr__fkt_etb_katego',
            'usr__fkt_etb_kategolink',
            'usr_etb_' . $codes['etb'] . '_katego',
            'usr_etb_' . $codes['etb'] . '_kategolink'
        ) === 0,
        'fixed ETB account received unrelated message/category tables'
    );

    $awIdentity = [
        'benutzer' => 'Aufnahme/Weitergabe',
        'kuerzel' => $codes['aw'],
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
    ];
    $messengerIdentity = [
        'benutzer' => 'Melder',
        'kuerzel' => $codes['messenger'],
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
    ];
    $s2Identity = [
        'benutzer' => 'Lage/Dokumentation',
        'kuerzel' => $codes['s2'],
        'funktion' => 'S2',
        'rolle' => 'Stab',
    ];
    $siIdentity = [
        'benutzer' => 'Sichter',
        'kuerzel' => $codes['si'],
        'funktion' => 'Si',
        'rolle' => 'Stab',
    ];
    $s6Identity = [
        'benutzer' => 'Fernmeldeplanung',
        'kuerzel' => $codes['s6'],
        'funktion' => 'S6',
        'rolle' => 'Stab',
    ];
    $ldfIdentity = [
        'benutzer' => 'Leiter FmZt',
        'kuerzel' => $codes['ldf'],
        'funktion' => 'LdF',
        'rolle' => 'Fernmelder',
    ];
    $assert(
        estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $awIdentity
        )['funktion'] === 'A/W'
            && estab_dv_require_operational_account(
                $connection,
                $incidentId,
                [
                    'benutzer' => 'Zweiter Sichter',
                    'kuerzel' => $codes['si_duplicate'],
                    'funktion' => 'Si',
                    'rolle' => 'Stab',
                ]
            )['funktion'] === 'Si',
        'a fixed account without historical staffing was denied'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            array_replace($awIdentity, ['funktion' => 'S2', 'rolle' => 'Stab'])
        ),
        'a forged function/role tuple authorized another capability'
    );
    $listedShifts = estab_dv_shift_list($connection, $incidentId);
    $assert(
        count($listedShifts) === 1
            && (int) $listedShifts[0]['dienstschicht_id'] === $shiftId
            && count($listedShifts[0]['besetzungen']) === 11,
        'real duty-shift UI read model did not return the active staffing'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_logbook_insert_entry(
            $databaseConfig,
            'nv_etb',
            'etb',
            [
                'event' => 'Unzulässiger Sichter-Eintrag',
                'comment' => 'Si ist nicht Lage/Dokumentation.',
                'event_time' => date('Y-m-d H:i:s'),
                'event_type' => 'information',
            ],
            $siIdentity
        ),
        'a fixed Si account wrote directly into the ETB domain'
    );
    $etbEntryId = estab_logbook_insert_entry(
        $databaseConfig,
        'nv_etb',
        'etb',
        [
            'event' => 'Eigenständige ETB-Funktion fachlich bestätigt',
            'comment' => 'Dasselbe Konto trägt getrennte ETB- und Si-Hüte.',
            'event_time' => date('Y-m-d H:i:s'),
            'event_type' => 'information',
        ],
        $etbIdentity
    );
    $assert(
        $etbEntryId > 0,
        'fixed ETB account could not write through EINSATZTAGEBUCH capability'
    );
    $assert(
        estab_logbook_is_designated_writer(
            $connection,
            $incidentId,
            $s2Identity,
            'etb'
        ),
        'fixed S2 account lost its ETB writing permission because an ETB '
            . 'account exists'
    );

    $expect(
        EstabDvPermissionException::class,
        static fn (): array => estab_dv_create_telecom_plan(
            $connection,
            $incidentId,
            [
                'benutzer' => 'Fernmeldeplanung',
                'kuerzel' => $codes['s6'],
                'funktion' => 'S2',
                'rolle' => 'Stab',
            ],
            [
                'herkunft' => 'Unzulässiger S2-Kontext',
                'gueltig_ab' => date('Y-m-d H:i:s', time() - 60),
                'gueltig_bis' => date('Y-m-d H:i:s', time() + 3600),
                'betriebsleitung' => 'Unzulässig',
                'bemerkungen' => '',
            ]
        ),
        'S6 privilege ignored the account’s fixed function'
    );
    $plan = estab_dv_create_telecom_plan(
        $connection,
        $incidentId,
        $s6Identity,
        [
            'herkunft' => 'S6 Führungsstelle',
            'gueltig_ab' => date('Y-m-d H:i:s', time() - 60),
            'gueltig_bis' => date('Y-m-d H:i:s', time() + 3600),
            'betriebsleitung' => 'LdF ' . $codes['ldf'],
            'bemerkungen' => 'Integrationsplan',
        ]
    );
    $planId = (int) $plan['fernmeldeplan_id'];
    $routeId = estab_dv_add_telecom_entry(
        $connection,
        $incidentId,
        $planId,
        $s6Identity,
        [
            'betriebsstelle' => 'Gegenstelle Integration',
            'rufname' => 'Integration 01',
            'medium' => 'Me',
            'kanal' => 'persönlich',
            'bandlage' => 'entfällt',
            'verkehrsform' => 'Melderbeförderung',
            'besondere_vermerke' => 'Identität am Ziel feststellen',
            'bemerkungen' => 'Rückweg über FmZt',
        ]
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $planId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_fernmeldeplaene` SET `version` = `version` + 1'
                . ' WHERE `fernmeldeplan_id` = ?'
            );
            try {
                $statement->bind_param('i', $planId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'draft telecommunications plan identity/version was mutable'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $planId, $codes): void {
            $statement = $connection->prepare(
                'UPDATE `nv_fernmeldeplaene`'
                . " SET `status` = 'AKTIV', `freigegeben_am` = NOW(6),"
                . ' `freigegeben_von` = ?'
                . ' WHERE `fernmeldeplan_id` = ?'
            );
            try {
                $statement->bind_param('si', $codes['aw'], $planId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'non-S6 direct SQL release activated a telecommunications plan'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $routeId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_fernmeldeplan_eintraege`'
                . ' SET `fernmeldeplan_eintrag_id` = ?'
                . ' WHERE `fernmeldeplan_eintrag_id` = ?'
            );
            $forgedRouteId = $routeId + 100000;
            try {
                $statement->bind_param('ii', $forgedRouteId, $routeId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'telecommunications route identity was mutable while in draft'
    );
    estab_dv_activate_telecom_plan(
        $connection,
        $incidentId,
        $planId,
        $s6Identity
    );
    $route = estab_dv_resolve_active_route(
        $connection,
        $incidentId,
        $routeId,
        'Me'
    );
    $assert(
        (int) $route['fernmeldeplan_eintrag_id'] === $routeId
            && (int) $route['version'] === 1,
        'active S6 route was not resolved with its immutable version'
    );

    $insertMessage = $connection->prepare(
        'INSERT INTO `nv_nachrichten`'
        . ' (`einsatz_id`, `04_richtung`, `04_nummer`, `06_befweg`,'
        . ' `06_befwegausw`, `estab_fernmeldeplan_eintrag_id`,'
        . ' `10_anschrift`, `12_inhalt`, `14_zeichen`, `14_funktion`,'
        . ' `x00_status`, `x01_abschluss`)'
        . " VALUES (?, 'A', 1, ?, 'Me', ?, ?, ?, ?, 'LdF', 2, 'f')"
    );
    if (!$insertMessage) {
        throw new RuntimeException('Could not prepare messenger test message');
    }
    try {
        $routeLabel = 'S6/V1 · Integration 01 · persönlich · Me';
        $destination = 'Gegenstelle Integration';
        $content = 'Nachricht zur nachgewiesenen Melderbeförderung';
        $insertMessage->bind_param(
            'iisiss',
            $incidentId,
            $routeLabel,
            $routeId,
            $destination,
            $content,
            $codes['ldf']
        );
        $insertMessage->execute();
        $messageId = (int) $connection->insert_id;
    } finally {
        $insertMessage->close();
    }
    $assert($messageId > 0, 'messenger test message was not created');
    $connection->begin_transaction();
    try {
        estab_message_event_append(
            $connection,
            $incidentId,
            $messageId,
            'dv_operations_fixture_created',
            [
                'benutzer' => 'Leiter FmZt',
                'kuerzel' => $codes['ldf'],
                'funktion' => 'LdF',
            ],
            null,
            2,
            ['fixture' => 'DV messenger transport']
        );
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }

    $messengerCandidates = estab_dv_messenger_candidates(
        $connection,
        $incidentId
    );
    $candidateCodes = array_column($messengerCandidates, 'kuerzel');
    sort($candidateCodes);
    $expectedCandidateCodes = [
        $codes['aw'],
        $codes['messenger'],
        $codes['aw_extension'],
    ];
    sort($expectedCandidateCodes);
    $assert(
        $candidateCodes === $expectedCandidateCodes
            && !in_array($codes['s2'], $candidateCodes, true),
        'messenger UI did not derive candidates from fixed active A/W accounts'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_dv_assign_messenger(
            $connection,
            $incidentId,
            $messageId,
            $codes['messenger'],
            'Gegenstelle Integration',
            [
                'benutzer' => 'Leiter FmZt',
                'kuerzel' => $codes['ldf'],
                'funktion' => 'S2',
                'rolle' => 'Stab',
            ]
        ),
        'LdF privilege ignored the account’s fixed function'
    );

    $cancelledJobId = estab_dv_assign_messenger(
        $connection,
        $incidentId,
        $messageId,
        $codes['messenger'],
        'Gegenstelle Integration',
        $ldfIdentity
    );
    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $cancelledJobId,
            'cancel',
            $ldfIdentity,
            ['abbruchgrund' => 'Vor Übernahme neu zu disponieren']
        ) === 'ABGEBROCHEN',
        'pre-acceptance cancellation was not retained'
    );
    $redispatchHistory = estab_dv_require_no_open_messenger_for_redispatch(
        $connection,
        $incidentId,
        $messageId
    );
    $assert(
        count($redispatchHistory) === 1
            && $redispatchHistory[0]['status'] === 'ABGEBROCHEN',
        'an aborted pre-acceptance run did not release the message for '
            . 'traceable redispatch'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_require_messenger_reported_for_message(
            $connection,
            $incidentId,
            $messageId
        ),
        'cancelled messenger chain passed the message completion gate'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): int => estab_dv_assign_messenger(
            $connection,
            $incidentId,
            $messageId,
            $codes['s2'],
            'Gegenstelle Integration',
            $ldfIdentity
        ),
        'an active S2 account was accepted as a messenger'
    );
    $jobId = estab_dv_assign_messenger(
        $connection,
        $incidentId,
        $messageId,
        $codes['messenger'],
        'Gegenstelle Integration',
        $ldfIdentity
    );
    $assert(
        $jobId !== $cancelledJobId,
        'a cancelled pre-acceptance run did not permit traceable reassignment'
    );

    $attachmentSession = 'dv_attach_' . $suffix;
    $attachmentName = estab_attachment_reserve(
        $connection,
        'nv_anhang',
        'DV',
        $attachmentSession,
        $messengerIdentity
    );

    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'accept',
            $messengerIdentity
        ) === 'UEBERNOMMEN',
        'messenger did not personally accept the job'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array =>
            estab_dv_require_no_open_messenger_for_redispatch(
                $connection,
                $incidentId,
                $messageId
            ),
        'an accepted messenger run did not block redispatch'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): string => estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'cancel',
            $ldfIdentity,
            ['abbruchgrund' => 'Unzulässiger Abbruch nach Übernahme']
        ),
        'an accepted messenger job was cancelled without return evidence'
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $jobId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_melderauftraege`'
                . " SET `status` = 'ABGEBROCHEN',"
                . ' `abgebrochen_am` = NOW(6), `abbruchgrund` = ?'
                . ' WHERE `melderauftrag_id` = ?'
            );
            $reason = 'Direkter Abbruch ohne Rückkehr';
            try {
                $statement->bind_param('si', $reason, $jobId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'database accepted post-acceptance cancellation without return'
    );
    $secondShift = estab_dv_create_shift(
        $connection,
        $incidentId,
        'Dienstschicht 2',
        $shiftId,
        $actor
    );
    $secondShiftId = (int) $secondShift['dienstschicht_id'];
    $setSuccessorOnline = $connection->prepare(
        'UPDATE `nv_benutzer` SET `aktiv` = ?,'
        . ' `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
        . ' WHERE BINARY `kuerzel` = BINARY ?'
    );
    if (!$setSuccessorOnline) {
        throw new RuntimeException('Could not prepare offline planning probe');
    }
    $offline = 0;
    $setSuccessorOnline->bind_param(
        'is',
        $offline,
        $codes['s2_successor']
    );
    $setSuccessorOnline->execute();
    foreach ($hatDefinitions as $accountKey => $function) {
        $successorAccountKey = $accountKey === 's2'
            ? 's2_successor'
            : $accountKey;
        $assignment = estab_dv_assign_hat(
            $connection,
            $incidentId,
            $secondShiftId,
            $codes[$successorAccountKey],
            $function,
            $actor
        );
        $secondAssignments[$accountKey] =
            (int) $assignment['dienstbesetzung_id'];
        if ($accountKey === 's2') {
            $offlineAcceptance = estab_dv_accept_hat(
                $connection,
                $incidentId,
                $secondAssignments[$accountKey],
                $codes[$successorAccountKey]
            );
            $assert(
                (int) $offlineAcceptance['dienstbesetzung_id']
                    === $secondAssignments[$accountKey],
                'offline but unblocked account could not retain its '
                    . 'personally recorded planned duty acceptance'
            );
            continue;
        }
        estab_dv_accept_hat(
            $connection,
            $incidentId,
            $secondAssignments[$accountKey],
            $codes[$successorAccountKey]
        );
    }
    foreach (
        [
            's3' => [$codes['s2_successor'], 'S3'],
            'etb' => [$codes['si'], 'ETB'],
        ] as $assignmentKey => [$accountCode, $function]
    ) {
        $assignment = estab_dv_assign_hat(
            $connection,
            $incidentId,
            $secondShiftId,
            $accountCode,
            $function,
            $actor
        );
        $secondAssignments[$assignmentKey] =
            (int) $assignment['dienstbesetzung_id'];
        if ($assignmentKey === 'etb') {
            continue;
        }
        estab_dv_accept_hat(
            $connection,
            $incidentId,
            $secondAssignments[$assignmentKey],
            $accountCode
        );
    }
    $assert(
        estab_dv_shift_required_hats($connection, $secondShiftId) === [],
        'offline but accepted unblocked account did not fulfil planned staffing'
    );
    $unacceptedSuccessor = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $secondShiftId,
        $codes['ldf_duplicate'],
        'A/W',
        $actor
    );
    $unacceptedSuccessorAssignmentId =
        (int) $unacceptedSuccessor['dienstbesetzung_id'];
    $assert(
        (string) $scalar(
            $connection,
            'SELECT `status` FROM `nv_dienstbesetzungen`'
                . ' WHERE `dienstbesetzung_id` = ?',
            'i',
            $unacceptedSuccessorAssignmentId
        ) === 'ZUGEWIESEN',
        'unaccepted successor roster fixture is not assigned-only'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_dv_initiate_handover_shift(
            $connection,
            $incidentId,
            $shiftId,
            $secondShiftId,
            'Unzulässige administrative Übergabe ohne persönliche Besetzung.',
            $assignments['s2'],
            [
                'benutzer' => 'Integration Administration',
                'kuerzel' => 'admin',
                'funktion' => 'S2',
                'rolle' => 'Stab',
            ],
            $actor
        ),
        'administrator initiated a handover without a personal accepted hat'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_dv_initiate_handover_shift(
            $connection,
            $incidentId,
            $shiftId,
            $secondShiftId,
            'Unzulässige Übergabe mit fremder Besetzungs-ID.',
            $assignments['s2'],
            $awIdentity,
            $actor
        ),
        'personally logged-in A/W claimed another accepted assignment for handover'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): null => (
            estab_dv_close_shift(
                $connection,
                $incidentId,
                $shiftId,
                $actor
            ) ?? null
        ),
        'active shift closed although successor operation was prepared'
    );
    $blockUser = $connection->prepare(
        'UPDATE `nv_benutzer` SET `estab_gesperrt` = ?'
        . ' WHERE BINARY `kuerzel` = BINARY ?'
    );
    if (!$blockUser) {
        throw new RuntimeException('Could not prepare blocked-user probe');
    }
    try {
        $blocked = 1;
        $blockUser->bind_param('is', $blocked, $codes['s6']);
        $blockUser->execute();
        $assert(
            estab_dv_shift_required_hats(
                $connection,
                $secondShiftId
            ) === ['S6'],
            'blocked S6 account still fulfilled a required successor hat'
        );
        $expect(
            EstabDvConflictException::class,
            static fn (): int => estab_dv_initiate_handover_shift(
                $connection,
                $incidentId,
                $shiftId,
                $secondShiftId,
                'Unzulässig mit gesperrter Pflichtbesetzung.',
                $assignments['s2'],
                $s2Identity,
                $actor
            ),
            'blocked account fulfilled handover mandatory staffing'
        );
        $blocked = 0;
        $blockUser->execute();
    } finally {
        $blockUser->close();
    }
    $expect(
        EstabDvConflictException::class,
        static fn (): null => (
            estab_dv_initiate_handover_shift(
                $connection,
                $incidentId,
                $shiftId,
                $secondShiftId,
                'Unzulässige Übergabe bei unterwegs befindlichem Melder.',
                $assignments['s2'],
                $s2Identity,
                $actor
            ) ?? null
        ),
        'shift handover superseded a messenger who was still away'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $messengerIdentity
            ) ?? null
        ),
        'messenger wrote another task while in status UEBERNOMMEN'
    );
    $uploadCallbackCalls = 0;
    $blockedJpegUpload = static function () use (
        &$uploadCallbackCalls,
        $attachmentName,
        $suffix
    ): array {
        $uploadCallbackCalls++;
        return [
            'filename' => $attachmentName . '.jpg',
            'org_filename' => 'lagefoto-' . $suffix . '.jpg',
            'comment' => 'DV Melderguard JPEG',
            'time' => date('Y-m-d H:i:s'),
            'md5hash' => md5('dv-jpeg-' . $suffix),
            'sha256' => hash('sha256', 'dv-jpeg-' . $suffix),
            'size' => strlen('dv-jpeg-' . $suffix),
        ];
    };
    $attachmentAudit = static fn (array $stored): string =>
        'dv-melderguard;' . $stored['filename'] . '.' . $stored['fileext'];
    $expect(
        EstabDvPermissionException::class,
        static fn (): ?array => estab_attachment_store_upload(
            $connection,
            'nv_anhang',
            'nv_protokoll',
            $attachmentName,
            $attachmentSession,
            $codes['messenger'],
            $messengerIdentity,
            'Anhangdaten speichern',
            $blockedJpegUpload,
            $attachmentAudit
        ),
        'JPEG attachment was stored while messenger was away'
    );
    $assert(
        $uploadCallbackCalls === 0,
        'blocked away upload touched JPEG bytes before authorization'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_logbook_insert_entry(
            $databaseConfig,
            'nv_tbb',
            'tbb',
            [
                'event' => 'Unzulässiger Parallelauftrag',
                'comment' => 'Melder ist unterwegs.',
            ],
            $messengerIdentity
        ),
        'messenger wrote TBB evidence while away'
    );

    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'deliver',
            $messengerIdentity,
            ['tatsaechlicher_empfaenger' => 'Frau Zielperson']
        ) === 'UEBERGEBEN',
        'actual recipient was not recorded at delivery'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $messengerIdentity
            ) ?? null
        ),
        'messenger wrote another task after delivery but before return path'
    );
    $expect(
        EstabDvInputException::class,
        static fn (): string => estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'return_path',
            $messengerIdentity,
            ['ruecknachricht' => 'Mehrdeutiger Rückweg']
        ),
        'return path accepted text without an explicit message decision'
    );
    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'return_path',
            $messengerIdentity,
            [
                'ruecknachricht_vorhanden' => 'ja',
                'ruecknachricht' => 'Empfang bestätigt',
            ]
        ) === 'RUECKWEG',
        'messenger return path was not recorded'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $messengerIdentity
            ) ?? null
        ),
        'messenger wrote another task while returning'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): ?array => estab_attachment_store_upload(
            $connection,
            'nv_anhang',
            'nv_protokoll',
            $attachmentName,
            $attachmentSession,
            $codes['messenger'],
            $messengerIdentity,
            'Anhangdaten speichern',
            $blockedJpegUpload,
            $attachmentAudit
        ),
        'JPEG attachment was stored while messenger was on the return path'
    );
    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'returned',
            $messengerIdentity
        ) === 'ZURUECK',
        'messenger return was not recorded'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_require_messenger_reported_for_message(
            $connection,
            $incidentId,
            $messageId
        ),
        'returned but unreported messenger chain passed completion'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): string => estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'report',
            $awIdentity,
            ['abschlussvermerk' => 'Unzulässige A/W-Abschlussmeldung']
        ),
        'a non-LdF A/W account recorded the final FmZt report'
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $jobId, $codes): void {
            $statement = $connection->prepare(
                'UPDATE `nv_melderauftraege`'
                . " SET `status` = 'GEMELDET', `gemeldet_am` = NOW(6),"
                . ' `gemeldet_an` = ?, `abschlussvermerk` = ?,'
                . ' `ziel` = ?, `tatsaechlicher_empfaenger` = ?'
                . ' WHERE `melderauftrag_id` = ?'
            );
            $report = 'Formal vollständiger, aber manipulierter Abschluss';
            $destination = 'Nachträglich geändertes Ziel';
            $recipient = 'Nachträglich geänderter Empfänger';
            try {
                $statement->bind_param(
                    'ssssi',
                    $codes['ldf'],
                    $report,
                    $destination,
                    $recipient,
                    $jobId
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'legitimate messenger transition altered destination or prior evidence'
    );
    $assert(
        estab_dv_transition_messenger(
            $connection,
            $incidentId,
            $jobId,
            'report',
            $ldfIdentity,
            ['abschlussvermerk' => 'Rückkehr und Empfang an FmZt gemeldet']
        ) === 'GEMELDET',
        'selected active LdF could not record the final FmZt report'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): array =>
            estab_dv_require_no_open_messenger_for_redispatch(
                $connection,
                $incidentId,
                $messageId
            ),
        'a completely reported messenger run did not block redispatch '
            . 'permanently'
    );
    $storedJpeg = estab_attachment_store_upload(
        $connection,
        'nv_anhang',
        'nv_protokoll',
        $attachmentName,
        $attachmentSession,
        $codes['messenger'],
        $messengerIdentity,
        'Anhangdaten speichern',
        $blockedJpegUpload,
        $attachmentAudit
    );
    $assert(
        is_array($storedJpeg)
            && ($storedJpeg['fileext'] ?? null) === 'jpg'
            && $uploadCallbackCalls === 1,
        'reported messenger did not regain JPEG attachment write access'
    );
    estab_dv_require_operational_account(
        $connection,
        $incidentId,
        $messengerIdentity
    );
    $assert(
        estab_logbook_is_designated_writer(
            $connection,
            $incidentId,
            $messengerIdentity,
            'tbb'
        ),
        'a fixed A/W account lost TTB permission because another A/W exists'
    );
    $tbbEntryId = estab_logbook_insert_entry(
        $databaseConfig,
        'nv_tbb',
        'tbb',
        [
            'entry_type' => 'betrieb_personal',
            'personnel_duty' => 'Melder zurück in der Führungsstelle',
            'comment' => 'TBB-Nachtrag durch ein festes A/W-Konto.',
        ],
        $awIdentity
    );
    $assert(
        $tbbEntryId > 0,
        'fixed A/W account could not record the returned messenger in the TBB'
    );
    $expect(
        EstabDvConflictException::class,
        static fn (): int => estab_dv_assign_messenger(
            $connection,
            $incidentId,
            $messageId,
            $codes['aw'],
            'Gegenstelle Integration',
            $ldfIdentity
        ),
        'a reported message was assigned a second time while still status 2'
    );
    $completedJob = estab_dv_require_messenger_reported_for_message(
        $connection,
        $incidentId,
        $messageId
    );
    $assert(
        (int) $completedJob['melderauftrag_id'] === $jobId,
        'completed messenger evidence did not bind the exact message'
    );
    $messengerSnapshots = estab_dv_verify_messenger_snapshots(
        $connection,
        $incidentId
    );
    $assert(
        $messengerSnapshots['valid'] === true
            && (int) $messengerSnapshots['jobs'] === 2,
        'terminal messenger rows do not match their canonical event snapshots'
    );

    // The legacy connection shim disables mysqli exception reporting when the
    // logbook opens its own connection. Re-enable strict reporting so the
    // following direct tamper probes can assert the database-trigger errors.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use (
            $connection,
            $incidentId,
            $messageId,
            $codes
        ): void {
            $statement = $connection->prepare(
                'INSERT INTO `nv_melderauftraege`'
                . ' (`einsatz_id`, `nachricht_id`, `melder_kuerzel`,'
                . ' `ziel`, `beauftragt_von`) VALUES (?, ?, ?, ?, ?)'
            );
            $forgedDestination = 'Zweiter Lauf nach Abschluss';
            try {
                $statement->bind_param(
                    'iisss',
                    $incidentId,
                    $messageId,
                    $codes['aw'],
                    $forgedDestination,
                    $codes['ldf']
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'database trigger allowed a second assignment after GEMELDET'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $jobId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_melderauftraege`'
                . ' SET `abschlussvermerk` = ?'
                . ' WHERE `melderauftrag_id` = ?'
            );
            $forgedReport = 'Nachträglich manipuliert';
            try {
                $statement->bind_param('si', $forgedReport, $jobId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'terminal messenger snapshot was mutable after final report'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $routeId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_fernmeldeplan_eintraege`'
                . ' SET `rufname` = ? WHERE `fernmeldeplan_eintrag_id` = ?'
            );
            $changedCallSign = 'Manipuliert';
            try {
                $statement->bind_param('si', $changedCallSign, $routeId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'an active telecommunications route was mutable'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $messageId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_nachrichten`'
                . ' SET `estab_fernmeldeplan_eintrag_id` = NULL'
                . ' WHERE `00_lfd` = ?'
            );
            try {
                $statement->bind_param('i', $messageId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'the disposed message route was mutable'
    );

    $storedS2 = estab_auth_fetch_session_user(
        $connection,
        'nv_benutzer',
        $codes['s2']
    );
    $assert(
        is_array($storedS2)
            && estab_auth_account_matches_session(
                $storedS2,
                $s2Identity,
                $phpSessionId
            ),
        'fixed S2 account session was invalid before historical handover'
    );
    $cancelledHandoverRequestId = estab_dv_initiate_handover_shift(
        $connection,
        $incidentId,
        $shiftId,
        $secondShiftId,
        'Vollständige Lage-, Nachrichten- und Auftragsübergabe.',
        $assignments['s2'],
        $s2Identity,
        $actor
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_dienstschichten`'
                . " WHERE `einsatz_id` = ? AND `status` = 'AKTIV'",
            'i',
            $incidentId
        ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_dienstuebergaben`'
                    . ' WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === 0,
        'admin initiation falsely claimed or executed successor confirmation'
    );
    estab_dv_cancel_handover_request(
        $connection,
        $incidentId,
        $cancelledHandoverRequestId,
        'Falsche Übergabezusammenfassung gewählt.',
        $actor
    );
    $assert(
        (string) $scalar(
            $connection,
            'SELECT `status` FROM `nv_dienstuebergabe_anfragen`'
                . ' WHERE `dienstuebergabe_anfrage_id` = ?',
            'i',
            $cancelledHandoverRequestId
        ) === 'STORNIERT',
        'an erroneous handover request was not retained as cancelled evidence'
    );
    $handoverRequestId = estab_dv_initiate_handover_shift(
        $connection,
        $incidentId,
        $shiftId,
        $secondShiftId,
        'Vollständige Lage-, Nachrichten- und Auftragsübergabe.',
        $assignments['s2'],
        $s2Identity,
        $actor
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_confirm_handover_shift(
                $connection,
                $incidentId,
                $handoverRequestId,
                $secondAssignments['s2'],
                [
                    'benutzer' => 'Zweiter Sichter',
                    'kuerzel' => $codes['si_duplicate'],
                    'funktion' => 'Si',
                    'rolle' => 'Stab',
                ]
            ) ?? null
        ),
        'an account outside the successor assignment confirmed the handover'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_confirm_handover_shift(
                $connection,
                $incidentId,
                $handoverRequestId,
                $secondAssignments['s2'],
                [
                    'benutzer' => 'Nachfolgende Lage/Dokumentation',
                    'kuerzel' => $codes['s2_successor'],
                    'funktion' => 'S2',
                    'rolle' => 'Stab',
                ]
            ) ?? null
        ),
        'offline successor account confirmed the current handover action'
    );
    $online = 1;
    $setSuccessorOnline->bind_param(
        'is',
        $online,
        $codes['s2_successor']
    );
    $setSuccessorOnline->execute();
    $setSuccessorOnline->close();
    $etbCountBeforeHandover = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $tbbCountBeforeHandover = (int) $scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $etbLastBeforeHandover = (int) $scalar(
        $connection,
        'SELECT MAX(`estab_book_lfd`) FROM `nv_etb` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $tbbLastBeforeHandover = (int) $scalar(
        $connection,
        'SELECT MAX(`estab_book_lfd`) FROM `nv_tbb` WHERE `einsatz_id` = ?',
        'i',
        $incidentId
    );
    $incomingHandoverIdentity = [
        'benutzer' => 'Nachfolgende Lage/Dokumentation',
        'kuerzel' => $codes['s2_successor'],
        'funktion' => 'S2',
        'rolle' => 'Stab',
    ];
    $handoverRollbackEvidence = $logbookEvidenceSnapshot(
        $connection,
        $incidentId
    );
    $handoverRollbackState = $handoverStateSnapshot(
        $connection,
        $incidentId,
        $handoverRequestId
    );
    $handoverCompletionProbe = estab_dv_database_now($connection);
    $handoverCompletionTime = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s.u',
        $handoverCompletionProbe
    );
    if (!$handoverCompletionTime instanceof DateTimeImmutable) {
        throw new RuntimeException('Could not parse handover completion probe');
    }
    $inconsistentCompletionProbe = $handoverCompletionTime
        ->modify('+1 second')
        ->format('Y-m-d H:i:s.u');
    $handoverSummary = (string) $scalar(
        $connection,
        'SELECT `zusammenfassung` FROM `nv_dienstuebergabe_anfragen`'
            . ' WHERE `dienstuebergabe_anfrage_id` = ?',
        'i',
        $handoverRequestId
    );
    $transitionProbeShifts = static function (
        mysqli $database,
        int $oldShiftId,
        int $newShiftId,
        string $completedAt
    ): void {
        $closeProbe = $database->prepare(
            "UPDATE `nv_dienstschichten` SET `status` = 'UEBERGEBEN',"
                . ' `beendet_am` = ? WHERE `dienstschicht_id` = ?'
                . " AND `status` = 'AKTIV'"
        );
        $activateProbe = $database->prepare(
            "UPDATE `nv_dienstschichten` SET `status` = 'AKTIV',"
                . ' `aktiviert_am` = ? WHERE `dienstschicht_id` = ?'
                . " AND `status` = 'GEPLANT'"
        );
        if (!$closeProbe || !$activateProbe) {
            $closeProbe?->close();
            $activateProbe?->close();
            throw new RuntimeException('Could not prepare handover time probe');
        }
        try {
            $closeProbe->bind_param('si', $completedAt, $oldShiftId);
            $activateProbe->bind_param('si', $completedAt, $newShiftId);
            $closeProbe->execute();
            $activateProbe->execute();
        } finally {
            $closeProbe->close();
            $activateProbe->close();
        }
    };
    $connection->begin_transaction();
    try {
        $transitionProbeShifts(
            $connection,
            $shiftId,
            $secondShiftId,
            $handoverCompletionProbe
        );
        $expect(
            mysqli_sql_exception::class,
            static function () use (
                $connection,
                $incidentId,
                $shiftId,
                $secondShiftId,
                $handoverSummary,
                $inconsistentCompletionProbe,
                $codes
            ): void {
                $statement = $connection->prepare(
                    'INSERT INTO `nv_dienstuebergaben`'
                        . ' (`einsatz_id`, `von_dienstschicht_id`,'
                        . ' `an_dienstschicht_id`, `zusammenfassung`,'
                        . ' `uebergeben_am`, `uebergeben_von`,'
                        . ' `angenommen_von`) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                try {
                    $statement->bind_param(
                        'iiissss',
                        $incidentId,
                        $shiftId,
                        $secondShiftId,
                        $handoverSummary,
                        $inconsistentCompletionProbe,
                        $codes['s2'],
                        $codes['s2_successor']
                    );
                    $statement->execute();
                } finally {
                    $statement->close();
                }
            },
            'database accepted contradictory completed-handover times'
        );
    } finally {
        $connection->rollback();
    }
    $connection->begin_transaction();
    try {
        $transitionProbeShifts(
            $connection,
            $shiftId,
            $secondShiftId,
            $handoverCompletionProbe
        );
        $insertProbe = $connection->prepare(
            'INSERT INTO `nv_dienstuebergaben`'
                . ' (`einsatz_id`, `von_dienstschicht_id`,'
                . ' `an_dienstschicht_id`, `zusammenfassung`,'
                . ' `uebergeben_am`, `uebergeben_von`, `angenommen_von`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $insertProbe->bind_param(
                'iiissss',
                $incidentId,
                $shiftId,
                $secondShiftId,
                $handoverSummary,
                $handoverCompletionProbe,
                $codes['s2'],
                $codes['s2_successor']
            );
            $insertProbe->execute();
            $probeHandoverId = (int) $connection->insert_id;
        } finally {
            $insertProbe->close();
        }
        $expect(
            mysqli_sql_exception::class,
            static function () use (
                $connection,
                $handoverRequestId,
                $secondAssignments,
                $codes,
                $probeHandoverId,
                $inconsistentCompletionProbe
            ): void {
                $statement = $connection->prepare(
                    'UPDATE `nv_dienstuebergabe_anfragen`'
                        . " SET `status` = 'BESTAETIGT',"
                        . ' `bestaetigt_am` = ?, `bestaetigt_von` = ?,'
                        . ' `bestaetigt_mit_besetzung_id` = ?,'
                        . ' `dienstuebergabe_id` = ?'
                        . ' WHERE `dienstuebergabe_anfrage_id` = ?'
                );
                try {
                    $statement->bind_param(
                        'ssiii',
                        $inconsistentCompletionProbe,
                        $codes['s2_successor'],
                        $secondAssignments['s2'],
                        $probeHandoverId,
                        $handoverRequestId
                    );
                    $statement->execute();
                } finally {
                    $statement->close();
                }
            },
            'database accepted contradictory handover confirmation times'
        );
    } finally {
        $connection->rollback();
    }
    $assert(
        $logbookEvidenceSnapshot($connection, $incidentId)
            === $handoverRollbackEvidence
            && $handoverStateSnapshot(
                $connection,
                $incidentId,
                $handoverRequestId
            ) === $handoverRollbackState,
        'rejected direct handover time contradictions changed durable evidence'
    );
    $connection->query(
        'DROP TRIGGER IF EXISTS `estab_test_handover_audit_failure`'
    );
    $connection->query(
        'CREATE TRIGGER `estab_test_handover_audit_failure`'
        . ' BEFORE INSERT ON `nv_protokoll` FOR EACH ROW'
        . ' BEGIN IF @estab_test_fail_handover = 1'
        . " AND BINARY NEW.`p_was` = BINARY 'DV Übergabe' THEN"
        . " SIGNAL SQLSTATE '45000'"
        . " SET MESSAGE_TEXT = 'injected handover audit failure';"
        . ' END IF; END'
    );
    try {
        $connection->query('SET @estab_test_fail_handover = 1');
        $expect(
            mysqli_sql_exception::class,
            static fn (): null => (
                estab_dv_confirm_handover_shift(
                    $connection,
                    $incidentId,
                    $handoverRequestId,
                    $secondAssignments['s2'],
                    $incomingHandoverIdentity
                ) ?? null
            ),
            'an injected final handover-audit failure did not abort confirmation'
        );
    } finally {
        $connection->query('SET @estab_test_fail_handover = NULL');
        $connection->query(
            'DROP TRIGGER `estab_test_handover_audit_failure`'
        );
    }
    $assert(
        $logbookEvidenceSnapshot($connection, $incidentId)
            === $handoverRollbackEvidence
            && $handoverStateSnapshot(
                $connection,
                $incidentId,
                $handoverRequestId
            ) === $handoverRollbackState
            && str_starts_with(
                (string) ($handoverRollbackState['request'] ?? ''),
                'INITIIERT:'
            ),
        'failed handover retained partial shifts, assignments, request, '
            . 'handover, ETB/TBB, heads or audit mutations'
    );
    estab_dv_confirm_handover_shift(
        $connection,
        $incidentId,
        $handoverRequestId,
        $secondAssignments['s2'],
        $incomingHandoverIdentity
    );
    $handoverInitiatedAt = (string) $scalar(
        $connection,
        "SELECT DATE_FORMAT(`initiiert_am`, '%Y-%m-%d %H:%i:%s.%f')"
            . ' FROM `nv_dienstuebergabe_anfragen`'
            . ' WHERE `dienstuebergabe_anfrage_id` = ?',
        'i',
        $handoverRequestId
    );
    $handoverTakenOverAt = (string) $scalar(
        $connection,
        "SELECT DATE_FORMAT(`bestaetigt_am`, '%Y-%m-%d %H:%i:%s.%f')"
            . ' FROM `nv_dienstuebergabe_anfragen`'
            . ' WHERE `dienstuebergabe_anfrage_id` = ?',
        'i',
        $handoverRequestId
    );
    $handoverInitiatedDisplay = estab_logbook_lifecycle_display_timestamp(
        $handoverInitiatedAt
    );
    $handoverTakenOverDisplay = estab_logbook_lifecycle_display_timestamp(
        $handoverTakenOverAt
    );
    $activeEtbAcceptanceEvidence = $logbookEvidenceSnapshot(
        $connection,
        $incidentId
    );
    $activeEtbAcceptanceRejection = $expect(
        EstabDvConflictException::class,
        static fn (): array => estab_dv_accept_hat(
            $connection,
            $incidentId,
            $secondAssignments['etb'],
            $codes['si']
        ),
        'planned ETB assignment displaced S2 after its shift became active'
    );
    $assert(
        str_contains(
            $activeEtbAcceptanceRejection->getMessage(),
            'dokumentierte und bestätigte Schichtübergabe'
        )
            && (string) $scalar(
                $connection,
                'SELECT `status` FROM `nv_dienstbesetzungen`'
                    . ' WHERE `dienstbesetzung_id` = ?',
                'i',
                $secondAssignments['etb']
            ) === 'ZUGEWIESEN'
            && $logbookEvidenceSnapshot($connection, $incidentId)
                === $activeEtbAcceptanceEvidence,
        'rejected active-shift ETB acceptance changed assignment or evidence'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?',
            'i',
            $incidentId
        ) === $etbCountBeforeHandover + 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?',
                'i',
                $incidentId
            ) === $tbbCountBeforeHandover + 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_book_lfd` = ?'
                    . " AND `etb_aktion` LIKE '%Dienstübergabe%'"
                    . " AND `etb_aktion` LIKE CONCAT('%Letzte ETB-Nr. vor der Übergabe: ', ?, '.%')",
                'iii',
                $incidentId,
                $etbLastBeforeHandover + 1,
                $etbLastBeforeHandover
            ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_book_lfd` = ?'
                    . " AND `estab_personnel_duty` LIKE '%Dienstübergabe%'"
                    . " AND `estab_personnel_duty` LIKE CONCAT('%Letzte TBB-Nr. vor der Übergabe: ', ?, '.%')",
                'iii',
                $incidentId,
                $tbbLastBeforeHandover + 1,
                $tbbLastBeforeHandover
            ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_book_lfd` = ?'
                    . " AND `etb_aktion` LIKE '%Zweiter Leiter FmZt%'",
                'ii',
                $incidentId,
                $etbLastBeforeHandover + 1
            ) === 0
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_book_lfd` = ?'
                    . " AND `estab_personnel_duty` LIKE '%Zweiter Leiter FmZt%'",
                'ii',
                $incidentId,
                $tbbLastBeforeHandover + 1
            ) === 0
            && (string) $scalar(
                $connection,
                'SELECT `status` FROM `nv_dienstbesetzungen`'
                    . ' WHERE `dienstbesetzung_id` = ?',
                'i',
                $unacceptedOutgoingAssignmentId
            ) === 'ZURUECKGEZOGEN'
            && (string) $scalar(
                $connection,
                'SELECT `status` FROM `nv_dienstbesetzungen`'
                    . ' WHERE `dienstbesetzung_id` = ?',
                'i',
                $unacceptedSuccessorAssignmentId
            ) === 'ZUGEWIESEN',
        'confirmed shift handover was not appended atomically to both logbooks'
    );
    $handoverEtbText = (string) $scalar(
        $connection,
        'SELECT `etb_aktion` FROM `nv_etb` WHERE `einsatz_id` = ?'
            . ' AND `estab_book_lfd` = ?',
        'ii',
        $incidentId,
        $etbLastBeforeHandover + 1
    );
    $handoverTbbText = (string) $scalar(
        $connection,
        'SELECT `estab_personnel_duty` FROM `nv_tbb`'
            . ' WHERE `einsatz_id` = ? AND `estab_book_lfd` = ?',
        'ii',
        $incidentId,
        $tbbLastBeforeHandover + 1
    );
    $assert(
        $handoverInitiatedAt !== ''
            && $handoverTakenOverAt !== ''
            && strcmp($handoverInitiatedAt, $handoverTakenOverAt) <= 0
            && str_contains(
                $handoverEtbText,
                'Persönlich übergeben von'
            )
            && str_contains(
                $handoverEtbText,
                '[' . $codes['s2'] . '] um ' . $handoverInitiatedDisplay
            )
            && str_contains($handoverEtbText, $handoverInitiatedDisplay)
            && str_contains(
                $handoverEtbText,
                'persönlich übernommen von'
            )
            && str_contains(
                $handoverEtbText,
                '[' . $codes['s2_successor'] . '] um '
                    . $handoverTakenOverDisplay
            )
            && str_contains($handoverEtbText, $handoverTakenOverDisplay)
            && str_contains(
                $handoverTbbText,
                '[' . $codes['s2'] . '] um ' . $handoverInitiatedDisplay
            )
            && str_contains(
                $handoverTbbText,
                '[' . $codes['s2_successor'] . '] um '
                    . $handoverTakenOverDisplay
            )
            && !str_contains(
                $handoverEtbText . $handoverTbbText,
                'keine dokumentierte Besetzung'
            )
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_dienstuebergabe_anfragen` AS request'
                    . ' JOIN `nv_dienstuebergaben` AS handover'
                    . ' ON handover.`dienstuebergabe_id` ='
                    . ' request.`dienstuebergabe_id`'
                    . ' JOIN `nv_dienstschichten` AS old_shift'
                    . ' ON old_shift.`dienstschicht_id` ='
                    . ' request.`von_dienstschicht_id`'
                    . ' JOIN `nv_dienstschichten` AS new_shift'
                    . ' ON new_shift.`dienstschicht_id` ='
                    . ' request.`an_dienstschicht_id`'
                    . ' WHERE request.`dienstuebergabe_anfrage_id` = ?'
                    . ' AND request.`bestaetigt_am` = handover.`uebergeben_am`'
                    . ' AND request.`bestaetigt_am` = old_shift.`beendet_am`'
                    . ' AND request.`bestaetigt_am` = new_shift.`aktiviert_am`',
                'i',
                $handoverRequestId
            ) === 1,
        'handover and takeover persons or their separate times are missing'
    );
    $storedOldS2 = estab_auth_fetch_session_user(
        $connection,
        'nv_benutzer',
        $codes['s2']
    );
    $storedSuccessorS2 = estab_auth_fetch_session_user(
        $connection,
        'nv_benutzer',
        $codes['s2_successor']
    );
    $successorS2Identity = [
        'benutzer' => 'Nachfolgende Lage/Dokumentation',
        'kuerzel' => $codes['s2_successor'],
        'funktion' => 'S2',
        'rolle' => 'Stab',
    ];
    $assert(
        is_array($storedOldS2)
            && is_array($storedSuccessorS2)
            && estab_auth_account_matches_session(
                $storedOldS2,
                $s2Identity,
                $phpSessionId
            )
            && estab_auth_account_matches_session(
                $storedSuccessorS2,
                $successorS2Identity,
                'dv-session-' . $suffix . '-' . $codes['s2_successor']
            ),
        'historical handover changed or revoked fixed account identities'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT old_hat.`nachfolger_id`'
                . ' FROM `nv_dienstbesetzungen` AS old_hat'
                . ' JOIN `nv_dienstbesetzungen` AS successor_hat'
                . ' ON successor_hat.`dienstbesetzung_id` ='
                . ' old_hat.`nachfolger_id`'
                . ' WHERE old_hat.`dienstbesetzung_id` = ?'
                . ' AND successor_hat.`dienstbesetzung_id` = ?'
                . ' AND BINARY old_hat.`benutzer_kuerzel` <>'
                . ' BINARY successor_hat.`benutzer_kuerzel`'
                . ' AND BINARY old_hat.`funktion` ='
                . ' BINARY successor_hat.`funktion`'
                . ' AND BINARY old_hat.`rolle` ='
                . ' BINARY successor_hat.`rolle`',
            'ii',
            $assignments['s2'],
            $secondAssignments['s2']
        ) === $secondAssignments['s2'],
        'personnel handover did not link a different successor account'
    );
    $handoverReadModel = estab_dv_shift_list($connection, $incidentId);
    $assert(
        count($handoverReadModel) === 2
            && $handoverReadModel[0]['status'] === 'AKTIV'
            && $handoverReadModel[1]['status'] === 'UEBERGEBEN',
        'duty-shift UI read model did not retain handover history'
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $shiftId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_dienstschichten` SET `bezeichnung` = ?'
                . ' WHERE `dienstschicht_id` = ?'
            );
            $forgedLabel = 'Nachträglich manipulierte Schicht';
            try {
                $statement->bind_param('si', $forgedLabel, $shiftId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'terminal duty shift evidence label was mutable'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $assignments): void {
            $statement = $connection->prepare(
                'UPDATE `nv_dienstbesetzungen`'
                . ' SET `angenommen_am` = DATE_ADD(`angenommen_am`,'
                . ' INTERVAL 1 SECOND)'
                . ' WHERE `dienstbesetzung_id` = ?'
            );
            try {
                $statement->bind_param('i', $assignments['s2']);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'terminal duty assignment acceptance evidence was mutable'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use (
            $connection,
            $cancelledHandoverRequestId
        ): void {
            $statement = $connection->prepare(
                'UPDATE `nv_dienstuebergabe_anfragen`'
                . ' SET `stornierungsgrund` = ?'
                . ' WHERE `dienstuebergabe_anfrage_id` = ?'
            );
            $forgedReason = 'Nachträglich umgedeutet';
            try {
                $statement->bind_param(
                    'si',
                    $forgedReason,
                    $cancelledHandoverRequestId
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'cancelled handover request evidence was mutable'
    );

    $chain = estab_dv_verify_event_chain($connection, $incidentId);
    $assert(
        $chain['valid'] === true && (int) $chain['events'] >= 35,
        'command-post event hash chain is incomplete or invalid'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $incidentId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_betriebsereignisse` SET `aktion` = ?'
                . ' WHERE `einsatz_id` = ? AND `sequenz` = 1'
            );
            $tamperedAction = 'tampered';
            try {
                $statement->bind_param(
                    'si',
                    $tamperedAction,
                    $incidentId
                );
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'command-post event ledger allowed an update'
    );
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $incidentId): void {
            $statement = $connection->prepare(
                'DELETE FROM `nv_betriebsereignisse`'
                . ' WHERE `einsatz_id` = ? AND `sequenz` = 1'
            );
            try {
                $statement->bind_param('i', $incidentId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'command-post event ledger allowed a delete'
    );

    $connection->begin_transaction();
    try {
        $openStatus = $connection->prepare(
            'UPDATE `nv_nachrichten` SET `x00_status` = 4'
                . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
        );
        if (!$openStatus) {
            throw new RuntimeException(
                'Could not prepare final-shift open-message fixture'
            );
        }
        try {
            $openStatus->bind_param('ii', $messageId, $incidentId);
            $openStatus->execute();
        } finally {
            $openStatus->close();
        }
        estab_message_event_append(
            $connection,
            $incidentId,
            $messageId,
            'dv_operations_fixture_open',
            [
                'benutzer' => 'Sichter',
                'kuerzel' => $codes['si'],
                'funktion' => 'Si',
            ],
            2,
            4,
            ['fixture' => 'Open status-4 closure blocker']
        );
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    $expect(
        EstabDvConflictException::class,
        static fn (): null => (
            estab_dv_close_shift(
                $connection,
                $incidentId,
                $secondShiftId,
                $actor
            ) ?? null
        ),
        'final active shift closed while a status-4 message was open'
    );
    $connection->begin_transaction();
    try {
        $terminalStatus = $connection->prepare(
            'UPDATE `nv_nachrichten`'
                . " SET `x00_status` = 8, `x01_abschluss` = 't'"
                . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
        );
        if (!$terminalStatus) {
            throw new RuntimeException(
                'Could not prepare final-shift terminal message fixture'
            );
        }
        try {
            $terminalStatus->bind_param('ii', $messageId, $incidentId);
            $terminalStatus->execute();
        } finally {
            $terminalStatus->close();
        }
        estab_message_event_append(
            $connection,
            $incidentId,
            $messageId,
            'dv_operations_fixture_completed',
            [
                'benutzer' => 'Sichter',
                'kuerzel' => $codes['si'],
                'funktion' => 'Si',
            ],
            4,
            8,
            ['fixture' => 'Completed before final shift close']
        );
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    $assert(
        estab_incident_close_preflight(
            $connection,
            $incidentId,
            null,
            $secondShiftId
        )['closable'] === true,
        'clean final-shift preflight still reported fachliche blockers'
    );
    estab_dv_close_shift(
        $connection,
        $incidentId,
        $secondShiftId,
        $actor
    );
    $restartShift = estab_dv_create_shift(
        $connection,
        $incidentId,
        'Unzulässiger Schichtneustart',
        null,
        $actor
    );
    $restartShiftId = (int) $restartShift['dienstschicht_id'];
    $expect(
        EstabDvConflictException::class,
        static fn (): null => (
            estab_dv_activate_initial_shift(
                $connection,
                $incidentId,
                $restartShiftId,
                $actor
            ) ?? null
        ),
        'initial activation reopened duty operation after historic activation'
    );
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $expect(
        mysqli_sql_exception::class,
        static function () use ($connection, $restartShiftId): void {
            $statement = $connection->prepare(
                'UPDATE `nv_dienstschichten`'
                . " SET `status` = 'AKTIV', `aktiviert_am` = NOW(6)"
                . ' WHERE `dienstschicht_id` = ?'
            );
            try {
                $statement->bind_param('i', $restartShiftId);
                $statement->execute();
            } finally {
                $statement->close();
            }
        },
        'database allowed initial activation after historic duty operation'
    );
    estab_dv_close_shift(
        $connection,
        $incidentId,
        $restartShiftId,
        $actor
    );
    $assert(
        estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $awIdentity
        )['funktion'] === 'A/W',
        'closing the historical duty shift blocked fixed-account writes'
    );
    $blockers = estab_dv_incident_closure_blockers(
        $connection,
        $incidentId
    );
    $assert(
        $blockers['offene_schichten'] === 0
            && $blockers['offene_besetzungen'] === 0
            && $blockers['offene_melderauftraege'] === 0
            && $blockers['offene_fernmeldeplanentwuerfe'] === 0
            && $blockers['offene_uebergabeanforderungen'] === 0
            && $blockers['betriebsereigniskette_gueltig'] === true,
        'closed command-post operation retained organisational blockers'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_dienstschichten`'
                . " WHERE `einsatz_id` = ? AND `status` = 'UEBERGEBEN'",
            'i',
            $incidentId
        ) === 1
            && (int) $scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_dienstschichten`'
                    . " WHERE `einsatz_id` = ? AND `status` = 'GESCHLOSSEN'",
                'i',
                $incidentId
            ) === 2,
        'shift handover/closure history was not retained'
    );

    printf(
        "DV operations integration: OK (%d assertions, %d events)\n",
        $assertions,
        (int) $blockers['betriebsereignisse']
    );
} finally {
    estab_auth_close($connection);
    if ($sessionStarted && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
}
