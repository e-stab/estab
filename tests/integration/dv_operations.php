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
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_active_hat_for_operational_write(
                $connection,
                $incidentId,
                $preShiftS2Identity
            ) ?? null
        ),
        'normal operational write was open before the first active shift'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): string => estab_attachment_reserve(
            $connection,
            'nv_anhang',
            'BOOT',
            'dv_boot_' . $suffix,
            $preShiftAwIdentity
        ),
        'attachment reservation was open before the first active shift'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): int => estab_logbook_insert_entry(
            $databaseConfig,
            'nv_tbb',
            'tbb',
            [
                'event' => 'Unzulässiger TBB-Bootstrap',
                'comment' => 'Keine aktive Dienstschicht.',
            ],
            $preShiftAwIdentity
        ),
        'TBB write was open before the first active shift'
    );

    $insertUser = $connection->prepare(
        'INSERT INTO `nv_benutzer`'
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `aktiv`,'
        . ' `estab_gesperrt`, `password`)'
        . ' VALUES (?, ?, ?, ?, ?, 1, 0, ?)'
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
        $codes['s2'],
        'S3',
        $actor
    );
    $assignments['s3'] = (int) $s3Assignment['dienstbesetzung_id'];
    $etbAssignment = estab_dv_assign_hat(
        $connection,
        $incidentId,
        $shiftId,
        $codes['si'],
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
        $codes['s2']
    );
    estab_dv_accept_hat(
        $connection,
        $incidentId,
        $assignments['etb'],
        $codes['si']
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
    $assert(
        estab_dv_shift_required_hats($connection, $shiftId) === [],
        'accepted initial shift still reports missing mandatory functions'
    );
    estab_dv_activate_initial_shift(
        $connection,
        $incidentId,
        $shiftId,
        $actor
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

    $_SESSION = [
        'vStab_benutzer' => 'Lage/Dokumentation',
        'vStab_kuerzel' => $codes['s2'],
        'vStab_funktion' => 'S2',
        'vStab_rolle' => 'Stab',
        'ROLLE' => 'Stab',
        'menue' => 'LOGIN',
    ];
    $selectedS2 = estab_dv_select_session_hat(
        $connection,
        $_SESSION,
        $incidentId,
        $assignments['s2']
    );
    $assert(
        $selectedS2['funktion'] === 'S2'
            && (int) $_SESSION['estab_duty_assignment_id']
                === $assignments['s2'],
        'base S2 hat could not be selected through the real session boundary'
    );
    $selectedS3 = estab_dv_select_session_hat(
        $connection,
        $_SESSION,
        $incidentId,
        $assignments['s3']
    );
    $s3Identity = $selectedS3 + [
        'duty_assignment_id' => $assignments['s3'],
    ];
    $assert(
        $selectedS3['funktion'] === 'S3'
            && $selectedS3['rolle'] === 'Stab'
            && $_SESSION['vStab_funktion'] === 'S3'
            && (int) $_SESSION['estab_duty_assignment_id']
                === $assignments['s3'],
        'one account did not switch from its S2 to its accepted S3 hat'
    );

    $s3ReadTable = estab_message_state_table(
        'usr_',
        'S3',
        $codes['s2'],
        'read'
    );
    $s3DoneTable = estab_message_state_table(
        'usr_',
        'S3',
        $codes['s2'],
        'done'
    );
    $s3FunctionCategory = 'usr__fkt_s3_katego';
    $s3FunctionLink = 'usr__fkt_s3_kategolink';
    $s3UserCategory = 'usr_s3_' . $codes['s2'] . '_katego';
    $s3UserLink = 'usr_s3_' . $codes['s2'] . '_kategolink';
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

    $conversation = estab_message_insert_numbered(
        $connection,
        $databaseName,
        'nv_nachrichten',
        'E',
        false,
        [
            '01_medium' => '',
            '01_datum' => date('Y-m-d H:i:s'),
            '01_zeichen' => $codes['s2'],
            '10_anschrift' => 'Führungsstelle Integration',
            '11_gesprnotiz' => 't',
            '12_inhalt' => 'S3-Gesprächsnotiz aus kombiniertem S2/S3-Konto',
            '12_abfzeit' => date('Y-m-d H:i:s'),
            '14_zeichen' => $codes['s2'],
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
                'author_code' => $codes['s2'],
                'author_function' => 'S3',
                'review_required' => false,
            ],
        ]
    );
    $conversationMessageId = (int) $conversation['id'];
    $assert(
        $conversationMessageId > 0,
        'selected S3 hat could not create a real conversation note'
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
        'selected S3 hat could not write its personal read state'
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
        'selected S3 hat could not write its function done state'
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
        'selected S3 hat could not persist function and user categories'
    );

    $bindSiSession = $connection->prepare(
        'UPDATE `nv_benutzer` SET `sid` = ?'
        . ' WHERE BINARY `kuerzel` = BINARY ?'
    );
    if (!$bindSiSession) {
        throw new RuntimeException('Could not bind real Si/ETB test session');
    }
    try {
        $bindSiSession->bind_param('ss', $phpSessionId, $codes['si']);
        $bindSiSession->execute();
    } finally {
        $bindSiSession->close();
    }
    $_SESSION = [
        'vStab_benutzer' => 'Sichter',
        'vStab_kuerzel' => $codes['si'],
        'vStab_funktion' => 'Si',
        'vStab_rolle' => 'Stab',
        'ROLLE' => 'Stab',
        'menue' => 'ROLLE',
    ];
    $selectedSi = estab_dv_select_session_hat(
        $connection,
        $_SESSION,
        $incidentId,
        $assignments['si']
    );
    $assert(
        $selectedSi['funktion'] === 'Si'
            && (int) $_SESSION['estab_duty_assignment_id']
                === $assignments['si'],
        'combined Si/ETB account could not select its Si hat'
    );
    $selectedEtb = estab_dv_select_session_hat(
        $connection,
        $_SESSION,
        $incidentId,
        $assignments['etb']
    );
    $etbIdentity = $selectedEtb + [
        'duty_assignment_id' => $assignments['etb'],
    ];
    $assert(
        $selectedEtb['funktion'] === 'ETB'
            && $selectedEtb['rolle'] === 'Stab'
            && $_SESSION['vStab_funktion'] === 'ETB'
            && (int) $_SESSION['estab_duty_assignment_id']
                === $assignments['etb'],
        'one account did not switch from its Si to its accepted ETB hat'
    );
    $assert(
        (int) $scalar(
            $connection,
            'SELECT COUNT(*) FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE()'
                . ' AND table_name IN (?, ?, ?, ?, ?, ?)',
            'ssssss',
            'usr_etb_' . $codes['si'] . '_read',
            'usr__fkt_etb_erl',
            'usr__fkt_etb_katego',
            'usr__fkt_etb_kategolink',
            'usr_etb_' . $codes['si'] . '_katego',
            'usr_etb_' . $codes['si'] . '_kategolink'
        ) === 0,
        'ETB-only hat received unrelated message/category tables'
    );

    $awIdentity = [
        'benutzer' => 'Aufnahme/Weitergabe',
        'kuerzel' => $codes['aw'],
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
        'duty_assignment_id' => $assignments['aw'],
    ];
    $messengerIdentity = [
        'benutzer' => 'Melder',
        'kuerzel' => $codes['messenger'],
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
        'duty_assignment_id' => $assignments['messenger'],
    ];
    $s2Identity = [
        'benutzer' => 'Lage/Dokumentation',
        'kuerzel' => $codes['s2'],
        'funktion' => 'S2',
        'rolle' => 'Stab',
        'duty_assignment_id' => $assignments['s2'],
    ];
    $siIdentity = [
        'benutzer' => 'Sichter',
        'kuerzel' => $codes['si'],
        'funktion' => 'Si',
        'rolle' => 'Stab',
        'duty_assignment_id' => $assignments['si'],
    ];
    $s6Identity = [
        'benutzer' => 'Fernmeldeplanung',
        'kuerzel' => $codes['s6'],
        'funktion' => 'S6',
        'rolle' => 'Stab',
        'duty_assignment_id' => $assignments['s6'],
    ];
    $ldfIdentity = [
        'benutzer' => 'Leiter FmZt',
        'kuerzel' => $codes['ldf'],
        'funktion' => 'LdF',
        'rolle' => 'Fernmelder',
        'duty_assignment_id' => $assignments['ldf'],
    ];
    estab_dv_require_active_hat_for_operational_write(
        $connection,
        $incidentId,
        $awIdentity
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_active_hat_for_operational_write(
                $connection,
                $incidentId,
                [
                    'benutzer' => $awIdentity['benutzer'],
                    'kuerzel' => $awIdentity['kuerzel'],
                    'funktion' => $awIdentity['funktion'],
                    'rolle' => $awIdentity['rolle'],
                ]
            ) ?? null
        ),
        'matching accepted primary function wrote without a selected hat'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_active_hat_for_operational_write(
                $connection,
                $incidentId,
                array_replace($awIdentity, [
                    'duty_assignment_id' => $assignments['s2'],
                ])
            ) ?? null
        ),
        'foreign selected assignment authorized another account/function'
    );
    $staleAwIdentity = $awIdentity;
    $staleAwIdentity['duty_assignment_id'] = PHP_INT_MAX;
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_active_hat_for_operational_write(
                $connection,
                $incidentId,
                $staleAwIdentity
            ) ?? null
        ),
        'stale selected assignment authorized an operational write'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_active_hat_for_operational_write(
                $connection,
                $incidentId,
                [
                    'benutzer' => 'Zweiter Sichter',
                    'kuerzel' => $codes['si_duplicate'],
                    'funktion' => 'Si',
                    'rolle' => 'Stab',
                ]
            ) ?? null
        ),
        'an unassigned account passed the active-hat write boundary'
    );
    $listedShifts = estab_dv_shift_list($connection, $incidentId);
    $assert(
        count($listedShifts) === 1
            && (int) $listedShifts[0]['dienstschicht_id'] === $shiftId
            && count($listedShifts[0]['besetzungen']) === 8,
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
        'an active Si hat wrote directly into the ETB domain'
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
        'active ETB hat could not write through EINSATZTAGEBUCH capability'
    );
    $s2EntryId = estab_logbook_insert_entry(
        $databaseConfig,
        'nv_etb',
        'etb',
        [
            'event' => 'Lage/Dokumentation fachlich bestätigt',
            'comment' => 'S2 führt das Einsatztagebuch.',
            'event_time' => date('Y-m-d H:i:s'),
            'event_type' => 'information',
        ],
        $s2Identity
    );
    $assert(
        $s2EntryId > 0,
        'active S2 lost its additional EINSATZTAGEBUCH capability'
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
                'duty_assignment_id' => $assignments['s2'],
            ],
            [
                'herkunft' => 'Unzulässiger S2-Kontext',
                'gueltig_ab' => date('Y-m-d H:i:s', time() - 60),
                'gueltig_bis' => date('Y-m-d H:i:s', time() + 3600),
                'betriebsleitung' => 'Unzulässig',
                'bemerkungen' => '',
            ]
        ),
        'S6 privilege ignored the currently selected S2 duty assignment'
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
    $expectedCandidateCodes = [$codes['aw'], $codes['messenger']];
    sort($expectedCandidateCodes);
    $assert(
        $candidateCodes === $expectedCandidateCodes
            && !in_array($codes['s2'], $candidateCodes, true),
        'messenger UI offered an account without accepted active A/W'
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
                'duty_assignment_id' => $assignments['s2'],
            ]
        ),
        'LdF privilege ignored the currently selected S2 duty assignment'
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
        'UPDATE `nv_benutzer` SET `aktiv` = ?'
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
                $actor
            ) ?? null
        ),
        'shift handover superseded a messenger who was still away'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_active_hat_for_operational_write(
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
            estab_dv_require_active_hat_for_operational_write(
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
            estab_dv_require_active_hat_for_operational_write(
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
    $tbbEntryId = estab_logbook_insert_entry(
        $databaseConfig,
        'nv_tbb',
        'tbb',
        [
            'event' => 'Melder zurück in der Führungsstelle',
            'comment' => 'Rückkehr und Abschlussmeldung sind nachgewiesen.',
        ],
        $messengerIdentity
    );
    $assert(
        $tbbEntryId > 0,
        'reported messenger did not regain normal operational write access'
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

    $assert(
        estab_auth_duty_assignment_matches_session(
            $connection,
            $assignments['s2'],
            $codes['s2'],
            'S2',
            'Stab'
        ),
        'accepted old-shift session hat was not valid before handover'
    );
    $cancelledHandoverRequestId = estab_dv_initiate_handover_shift(
        $connection,
        $incidentId,
        $shiftId,
        $secondShiftId,
        'Vollständige Lage-, Nachrichten- und Auftragsübergabe.',
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
    );
    $assert(
        !estab_auth_duty_assignment_matches_session(
            $connection,
            $assignments['s2'],
            $codes['s2'],
            'S2',
            'Stab'
        )
            && estab_auth_duty_assignment_matches_session(
                $connection,
                $secondAssignments['s2'],
                $codes['s2_successor'],
                'S2',
                'Stab'
            ),
        'handover did not revoke the old hat and activate the successor hat'
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
    $expect(
        EstabDvPermissionException::class,
        static fn (): null => (
            estab_dv_require_active_hat_for_operational_write(
                $connection,
                $incidentId,
                $awIdentity
            ) ?? null
        ),
        'normal operational write reopened after the active shift closed'
    );
    $expect(
        EstabDvPermissionException::class,
        static fn (): string => estab_attachment_reserve(
            $connection,
            'nv_anhang',
            'DV',
            'dv_closed_' . $suffix,
            $awIdentity
        ),
        'attachment write reopened after the active shift closed'
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
