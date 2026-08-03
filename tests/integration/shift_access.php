<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/shift_access.php';
require_once dirname(__DIR__, 2) . '/app/logbook.php';

function shift_access_integration_confirmation_version(
    mysqli $connection,
    int $incidentId,
    int $shiftId
): string {
    return estab_shift_access_confirmation_version(
        estab_shift_access_list($connection, $incidentId),
        $shiftId
    );
}

$databaseName = getenv('ESTAB_DB_NAME') ?: '';
if ($databaseName !== 'estab_shift_access_ci_test') {
    fwrite(
        STDERR,
        "Refusing to run access-shift integration outside its isolated database\n"
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
    fwrite(STDERR, "Access-shift integration database password is required\n");
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

if (getenv('ESTAB_SHIFT_ACCESS_WORKER') === 'disable') {
    $workerIncidentId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
    $workerShiftId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
    $readyPath = getenv('ESTAB_SHIFT_ACCESS_WORKER_READY');
    if (
        !is_int($workerIncidentId)
        || $workerIncidentId < 1
        || !is_int($workerShiftId)
        || $workerShiftId < 1
        || !is_string($readyPath)
        || $readyPath === ''
    ) {
        fwrite(STDERR, "Invalid access-shift worker input\n");
        estab_auth_close($connection);
        exit(2);
    }
    if (file_put_contents($readyPath, "ready\n", LOCK_EX) === false) {
        fwrite(STDERR, "Could not publish access-shift worker readiness\n");
        estab_auth_close($connection);
        exit(2);
    }
    try {
        $workerVersion = shift_access_integration_confirmation_version(
            $connection,
            $workerIncidentId,
            $workerShiftId
        );
        $workerResult = estab_shift_access_set_enabled(
            $connection,
            $workerIncidentId,
            $workerShiftId,
            false,
            true,
            $workerVersion,
            'shift-access-worker'
        );
        echo json_encode(
            $workerResult,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ) . "\n";
    } finally {
        estab_auth_close($connection);
    }
    exit(0);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectPermissionDenial = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (EstabDvPermissionException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$expectShiftConflict = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (EstabShiftAccessConflictException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$accountRow = static function (mysqli $connection, string $code): array {
    $statement = $connection->prepare(
        'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`, `aktiv`, `sid`,'
        . ' `ip`, `fwdip`, `estab_gesperrt` FROM `nv_benutzer`'
        . ' WHERE BINARY `kuerzel` = BINARY ? LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare access-shift account read');
    }
    try {
        $statement->bind_param('s', $code);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        throw new RuntimeException('Access-shift fixture account is missing');
    }
    return $row;
};
$setSession = static function (
    mysqli $connection,
    string $code,
    string $sessionId,
    bool $active = true
): void {
    $statement = $connection->prepare(
        'UPDATE `nv_benutzer` SET `aktiv` = ?, `sid` = ?, '
        . '`ip` = ?, `fwdip` = \'\','
        . ' `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
        . ' WHERE BINARY `kuerzel` = BINARY ?'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare access-shift session fixture');
    }
    try {
        $activeValue = $active ? 1 : 0;
        $ip = $active ? '127.0.0.1' : '';
        $statement->bind_param('isss', $activeValue, $sessionId, $ip, $code);
        $statement->execute();
        if ($statement->affected_rows !== 1) {
            throw new RuntimeException('Could not update access-shift session fixture');
        }
    } finally {
        $statement->close();
    }
};
$identityFor = static function (array $row): array {
    return [
        'benutzer' => (string) $row['benutzer'],
        'kuerzel' => (string) $row['kuerzel'],
        'funktion' => (string) $row['funktion'],
        'rolle' => (string) $row['rolle'],
    ];
};

try {
    $status = estab_incident_status($connection);
    $strictIncident = estab_incident_create(
        $connection,
        [
            'kennung' => 'ACCESS-SHIFT-STRICT-001',
            'name' => 'Strenger Zugangsschicht-Grenztest',
            'beginn' => date('Y-m-d\TH:i', time() - 3600),
            'ort' => 'Integration',
            'organisation' => 'THW',
            'fuehrungsstellenname' => 'Führungsstelle Zugangstest Streng',
            'einsatzleitung' => 'Testleitung',
            'beschreibung' =>
                'Leerer STRICT-Einsatz für den Zugangsschicht-Grenztest.',
            'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
        ],
        'shift-access-strict-negative',
        true,
        (int) $status['revision']
    );
    $strictIncidentId = (int) $strictIncident['einsatz_id'];
    $assert(
        ($strictIncident['estab_permission_mode'] ?? null)
            === ESTAB_PERMISSION_MODE_STRICT,
        'strict access-shift boundary fixture was not created in STRICT mode'
    );
    $expectShiftConflict(
        static fn (): array => estab_shift_access_create(
            $connection,
            $strictIncidentId,
            'Im strengen Modus unzulässig',
            null,
            null,
            'shift-access-strict-negative'
        ),
        'STRICT admitted an optional access-shift mutation'
    );
    $assert(
        (int) $connection->query(
            'SELECT COUNT(*) FROM `nv_zugangsschichten`'
                . ' WHERE `einsatz_id` = ' . $strictIncidentId
        )->fetch_row()[0] === 0,
        'rejected STRICT access-shift mutation left partial data'
    );

    $status = estab_incident_status($connection);
    $incident = estab_incident_create(
        $connection,
        [
            'kennung' => 'ACCESS-SHIFT-001',
            'name' => 'Optionale Zugangsschichten',
            'beginn' => date('Y-m-d\TH:i', time() - 3600),
            'ort' => 'Integration',
            'organisation' => 'THW',
            'fuehrungsstellenname' => 'Führungsstelle Zugangstest',
            'einsatzleitung' => 'Testleitung',
            'beschreibung' => 'Nachweis der optionalen Gruppenzugangssteuerung.',
            'estab_permission_mode' => ESTAB_PERMISSION_MODE_LOOSE,
        ],
        'shift-access-integration',
        true,
        (int) $status['revision'],
        true
    );
    $incidentId = (int) $incident['einsatz_id'];
    $assert(
        ($incident['estab_permission_mode'] ?? null)
            === ESTAB_PERMISSION_MODE_LOOSE,
        'access-shift fixture did not activate explicitly in LOOSE mode'
    );

    $accounts = [
        ['ung001', 'Unzugeordnet', 'S2', 'Stab'],
        ['grp001', 'Gruppenmitglied', 'S1', 'Stab'],
        ['or0001', 'ODER Mitglied', 'S6', 'Stab'],
        ['blk001', 'Manuell gesperrt', 'A/W', 'Fernmelder'],
        ['off001', 'Offline Mitglied', 'LdF', 'Fernmelder'],
        ['rac001', 'Parallel angemeldet', 'S3', 'Stab'],
    ];
    $insert = $connection->prepare(
        'INSERT INTO `nv_benutzer`'
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `ip`,'
        . ' `fwdip`, `aktiv`, `estab_letzte_aktivitaet`, `estab_gesperrt`,'
        . ' `password`)'
        . " VALUES (?, ?, ?, ?, ?, '127.0.0.1', '', 1,"
        . ' UTC_TIMESTAMP(6), 0, ?)'
    );
    if (!$insert) {
        throw new RuntimeException('Could not prepare access-shift accounts');
    }
    try {
        foreach ($accounts as [$code, $name, $function, $role]) {
            $sessionId = 'access-shift-' . $code;
            $passwordHash = password_hash(
                'access shift integration ' . $code,
                PASSWORD_DEFAULT
            );
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Could not hash access-shift password');
            }
            $insert->bind_param(
                'ssssss',
                $name,
                $code,
                $function,
                $role,
                $sessionId,
                $passwordHash
            );
            $insert->execute();
        }
    } finally {
        $insert->close();
    }

    $unassigned = $accountRow($connection, 'ung001');
    $assert(
        estab_auth_shift_access_state($connection, 'ung001') === [
            'managed' => false,
            'allowed' => true,
            'memberships' => 0,
            'active_memberships' => 0,
        ],
        'an unassigned account was not admitted independently'
    );
    $assert(
        estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $identityFor($unassigned)
        )['funktion'] === 'S2',
        'an unassigned LOOSE fixed account needed a formal duty shift'
    );
    $legacyShiftCount = (int) $connection->query(
        'SELECT COUNT(*) FROM `nv_dienstschichten`'
    )->fetch_row()[0];
    $assert(
        $legacyShiftCount === 0,
        'the LOOSE access test accidentally depended on a formal duty shift'
    );
    $etbId = estab_logbook_insert_entry(
        $databaseConfig,
        'nv_etb',
        'etb',
        [
            'event' => 'ETB-Eintrag ohne formale Dienstschicht',
            'comment' => 'Autorisierung ausschließlich über das feste S2-Konto.',
            'event_time' => date('Y-m-d H:i:s'),
            'event_type' => 'information',
        ],
        $identityFor($unassigned)
    );
    $unassignedAw = $accountRow($connection, 'blk001');
    $ttbId = estab_logbook_insert_entry(
        $databaseConfig,
        'nv_tbb',
        'tbb',
        [
            'entry_type' => 'betrieb_personal',
            'event_time' => date('Y-m-d H:i:s'),
            'personnel_duty' => 'A/W im Dienst ohne formale Dienstschicht',
            'operations' => 'Fester Kontozugang nachgewiesen',
            'comment' => 'Keine Dienstschicht als Eingabesperre.',
        ],
        $identityFor($unassignedAw)
    );
    $provenance = $connection->query(
        'SELECT '
        . "(SELECT CONCAT(COALESCE(`estab_shift_id`, 0), ':',"
        . ' COALESCE(`estab_writer_assignment_id`, 0)) FROM `nv_etb`'
        . " WHERE `etb_lfd-nr` = {$etbId}),"
        . "(SELECT CONCAT(COALESCE(`estab_shift_id`, 0), ':',"
        . ' COALESCE(`estab_writer_assignment_id`, 0)) FROM `nv_tbb`'
        . " WHERE `tbb_lfd-nr` = {$ttbId})"
    )->fetch_row();
    $assert(
        $etbId > 0
            && $ttbId > 0
            && $provenance === ['0:0', '0:0'],
        'ETB/TBB input still required or forged legacy shift provenance'
    );

    $disabledA = estab_shift_access_create(
        $connection,
        $incidentId,
        'Bereitschaft A',
        null,
        null,
        'shift-access-integration'
    );
    $disabledAId = (int) $disabledA['zugangsschicht_id'];
    $memberResult = estab_shift_access_add_member(
        $connection,
        $incidentId,
        $disabledAId,
        'grp001',
        'shift-access-integration'
    );
    $assert(
        $memberResult['session_revoked'] === true,
        'assigning an online account only to a disabled shift did not revoke it'
    );
    $groupAccount = $accountRow($connection, 'grp001');
    $assert(
        (int) $groupAccount['aktiv'] === 0
            && $groupAccount['sid'] === ''
            && $groupAccount['ip'] === ''
            && $groupAccount['fwdip'] === '',
        'disabled-shift assignment did not clear the complete session atomically'
    );
    $assert(
        estab_auth_shift_access_state($connection, 'grp001') === [
            'managed' => true,
            'allowed' => false,
            'memberships' => 1,
            'active_memberships' => 0,
        ],
        'an account assigned only to a disabled shift remained admissible'
    );
    $setSession($connection, 'grp001', 'access-shift-forged-login');
    $session = [
        'vStab_benutzer' => 'Gruppenmitglied',
        'vStab_kuerzel' => 'grp001',
        'vStab_funktion' => 'S1',
        'vStab_rolle' => 'Stab',
    ];
    $assert(
        estab_auth_current_session_identity(
            $session,
            $databaseConfig,
            'nv_benutzer',
            'access-shift-forged-login'
        ) === null
            && $session === ['menue' => 'LOGIN'],
        'a session assigned only to a disabled shift survived validation'
    );
    $firstMembershipId = (int) $memberResult[
        'zugangsschicht_mitglied_id'
    ];
    estab_shift_access_remove_member(
        $connection,
        $incidentId,
        $disabledAId,
        'grp001',
        $firstMembershipId,
        shift_access_integration_confirmation_version(
            $connection,
            $incidentId,
            $disabledAId
        ),
        'shift-access-integration'
    );
    $setSession($connection, 'grp001', 'access-shift-after-remove');
    $assert(
        estab_auth_shift_access_state($connection, 'grp001') === [
            'managed' => false,
            'allowed' => true,
            'memberships' => 0,
            'active_memberships' => 0,
        ]
            && estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $identityFor($accountRow($connection, 'grp001'))
            )['funktion'] === 'S1',
        'removing the final membership did not restore unmanaged account access'
    );
    $readded = estab_shift_access_add_member(
        $connection,
        $incidentId,
        $disabledAId,
        'grp001',
        'shift-access-integration'
    );
    $secondMembershipId = (int) $readded[
        'zugangsschicht_mitglied_id'
    ];
    try {
        estab_shift_access_remove_member(
            $connection,
            $incidentId,
            $disabledAId,
            'grp001',
            $firstMembershipId,
            shift_access_integration_confirmation_version(
                $connection,
                $incidentId,
                $disabledAId
            ),
            'shift-access-integration'
        );
        $assert(false, 'a stale remove dialog removed a re-added membership');
    } catch (EstabShiftAccessConflictException) {
        $assert(true, 'a stale membership interval was rejected');
    }
    $history = $connection->query(
        'SELECT `zugangsschicht_mitglied_id`, `zugeordnet_am`, `entfernt_am`'
        . ' FROM `nv_zugangsschicht_mitglieder`'
        . " WHERE `zugangsschicht_id` = {$disabledAId}"
        . " AND BINARY `benutzer_kuerzel` = BINARY 'grp001'"
        . ' ORDER BY `zugangsschicht_mitglied_id`'
    )->fetch_all(MYSQLI_ASSOC);
    $assert(
        $firstMembershipId > 0
            && $secondMembershipId > $firstMembershipId
            && $readded['session_revoked'] === true
            && count($history) === 2
            && (int) $history[0]['zugangsschicht_mitglied_id']
                === $firstMembershipId
            && $history[0]['entfernt_am'] !== null
            && (int) $history[1]['zugangsschicht_mitglied_id']
                === $secondMembershipId
            && $history[1]['entfernt_am'] === null
            && strcmp(
                (string) $history[0]['zugeordnet_am'],
                (string) $history[0]['entfernt_am']
            ) <= 0
            && strcmp(
                (string) $history[0]['entfernt_am'],
                (string) $history[1]['zugeordnet_am']
            ) <= 0,
        'remove/re-add did not retain two append-only membership intervals'
    );

    $enabledB = estab_shift_access_create(
        $connection,
        $incidentId,
        'Bereitschaft B',
        null,
        null,
        'shift-access-integration'
    );
    $enabledBId = (int) $enabledB['zugangsschicht_id'];
    estab_shift_access_add_member(
        $connection,
        $incidentId,
        $disabledAId,
        'or0001',
        'shift-access-integration'
    );
    estab_shift_access_add_member(
        $connection,
        $incidentId,
        $enabledBId,
        'or0001',
        'shift-access-integration'
    );
    $staleDisabledAVersion = shift_access_integration_confirmation_version(
        $connection,
        $incidentId,
        $disabledAId
    );
    $enable = estab_shift_access_set_enabled(
        $connection,
        $incidentId,
        $enabledBId,
        true,
        false,
        shift_access_integration_confirmation_version(
            $connection,
            $incidentId,
            $enabledBId
        ),
        'shift-access-integration'
    );
    try {
        estab_shift_access_set_enabled(
            $connection,
            $incidentId,
            $disabledAId,
            false,
            false,
            $staleDisabledAVersion,
            'shift-access-integration'
        );
        $assert(false, 'stale confirmation ignored a sibling shift change');
    } catch (EstabShiftAccessConflictException) {
        $assert(true, 'stale cross-shift confirmation was rejected');
    }
    estab_shift_access_add_member(
        $connection,
        $incidentId,
        $enabledBId,
        'grp001',
        'shift-access-integration'
    );
    $staleRemovalVersion = shift_access_integration_confirmation_version(
        $connection,
        $incidentId,
        $disabledAId
    );
    estab_shift_access_set_enabled(
        $connection,
        $incidentId,
        $enabledBId,
        false,
        true,
        shift_access_integration_confirmation_version(
            $connection,
            $incidentId,
            $enabledBId
        ),
        'shift-access-integration'
    );
    try {
        estab_shift_access_remove_member(
            $connection,
            $incidentId,
            $disabledAId,
            'grp001',
            $secondMembershipId,
            $staleRemovalVersion,
            'shift-access-integration'
        );
        $assert(false, 'stale removal ignored a changed sibling shift');
    } catch (EstabShiftAccessConflictException) {
        $assert(
            estab_auth_shift_access_state($connection, 'grp001') === [
                'managed' => true,
                'allowed' => false,
                'memberships' => 2,
                'active_memberships' => 0,
            ],
            'stale removal changed membership or access state'
        );
    }
    estab_shift_access_set_enabled(
        $connection,
        $incidentId,
        $enabledBId,
        true,
        false,
        shift_access_integration_confirmation_version(
            $connection,
            $incidentId,
            $enabledBId
        ),
        'shift-access-integration'
    );
    $assert(
        $enable['changed'] === true
            && $enable['revoked_accounts'] === []
            && estab_auth_shift_access_allowed($connection, 'or0001'),
        'one enabled membership did not admit an account by OR semantics'
    );
    $setSession($connection, 'or0001', 'access-shift-or-session');
    $unchangedFunction = $accountRow($connection, 'or0001');
    $assert(
        $unchangedFunction['funktion'] === 'S6'
            && $unchangedFunction['rolle'] === 'Stab',
        'access-shift membership changed the fixed function or role'
    );
    $disableAlreadyInactive = estab_shift_access_set_enabled(
        $connection,
        $incidentId,
        $disabledAId,
        false,
        false,
        shift_access_integration_confirmation_version(
            $connection,
            $incidentId,
            $disabledAId
        ),
        'shift-access-integration'
    );
    $assert(
        $disableAlreadyInactive['changed'] === false
            && (int) $accountRow($connection, 'or0001')['aktiv'] === 1,
        'an inactive sibling membership overruled an enabled membership'
    );
    $disableLastActive = estab_shift_access_set_enabled(
        $connection,
        $incidentId,
        $enabledBId,
        false,
        true,
        shift_access_integration_confirmation_version(
            $connection,
            $incidentId,
            $enabledBId
        ),
        'shift-access-integration'
    );
    $orAccount = $accountRow($connection, 'or0001');
    $assert(
        $disableLastActive['revoked_accounts'] === ['or0001']
            && (int) $orAccount['aktiv'] === 0
            && $orAccount['sid'] === ''
            && !estab_auth_shift_access_allowed($connection, 'or0001'),
        'disabling the last enabled membership did not revoke the account'
    );

    estab_shift_access_add_member(
        $connection,
        $incidentId,
        $enabledBId,
        'off001',
        'shift-access-integration'
    );
    $setSession($connection, 'off001', '', false);
    estab_shift_access_set_enabled(
        $connection,
        $incidentId,
        $enabledBId,
        true,
        false,
        shift_access_integration_confirmation_version(
            $connection,
            $incidentId,
            $enabledBId
        ),
        'shift-access-integration'
    );
    $offlineAccount = $accountRow($connection, 'off001');
    $assert(
        (int) $offlineAccount['aktiv'] === 0
            && $offlineAccount['sid'] === ''
            && estab_auth_shift_access_allowed($connection, 'off001'),
        'enabling a group logged an offline account in automatically'
    );

    $raceShift = estab_shift_access_create(
        $connection,
        $incidentId,
        'Paralleltest Anmeldung',
        null,
        null,
        'shift-access-integration'
    );
    $raceShiftId = (int) $raceShift['zugangsschicht_id'];
    estab_shift_access_add_member(
        $connection,
        $incidentId,
        $raceShiftId,
        'rac001',
        'shift-access-integration'
    );
    estab_shift_access_set_enabled(
        $connection,
        $incidentId,
        $raceShiftId,
        true,
        false,
        shift_access_integration_confirmation_version(
            $connection,
            $incidentId,
            $raceShiftId
        ),
        'shift-access-integration'
    );
    $loginConnection = estab_auth_connect($databaseConfig);
    $loginLock = estab_user_admin_account_lock_name(
        estab_shift_access_database($loginConnection),
        'nv_benutzer',
        'rac001'
    );
    $workerReady = sys_get_temp_dir() . '/estab-shift-access-'
        . bin2hex(random_bytes(8)) . '.ready';
    $worker = null;
    $workerPipes = [];
    try {
        estab_user_admin_acquire_account_lock($loginConnection, $loginLock);
        $setSession(
            $loginConnection,
            'rac001',
            'access-shift-concurrent-login'
        );
        $workerEnvironment = getenv();
        if (!is_array($workerEnvironment)) {
            throw new RuntimeException('Could not read access-shift worker environment');
        }
        $workerEnvironment['ESTAB_SHIFT_ACCESS_WORKER'] = 'disable';
        $workerEnvironment['ESTAB_SHIFT_ACCESS_WORKER_READY'] = $workerReady;
        $worker = proc_open(
            [
                PHP_BINARY,
                '-d',
                'auto_prepend_file=',
                __FILE__,
                (string) $incidentId,
                (string) $raceShiftId,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $workerPipes,
            dirname(__DIR__, 2),
            $workerEnvironment
        );
        if (!is_resource($worker)) {
            throw new RuntimeException('Could not start access-shift worker');
        }
        fclose($workerPipes[0]);
        $readyDeadline = microtime(true) + 5.0;
        while (!is_file($workerReady) && microtime(true) < $readyDeadline) {
            usleep(20_000);
        }
        if (!is_file($workerReady)) {
            throw new RuntimeException('Access-shift worker did not become ready');
        }
        usleep(300_000);
        $workerStatus = proc_get_status($worker);
        $raceBeforeRelease = $accountRow($connection, 'rac001');
        $raceShiftBeforeRelease = estab_shift_access_list(
            $connection,
            $incidentId
        );
        $raceShiftStillEnabled = false;
        foreach ($raceShiftBeforeRelease as $listedShift) {
            if ((int) $listedShift['zugangsschicht_id'] === $raceShiftId) {
                $raceShiftStillEnabled =
                    (int) $listedShift['zugang_aktiv'] === 1;
            }
        }
        $assert(
            ($workerStatus['running'] ?? false) === true
                && $raceShiftStillEnabled
                && (int) $raceBeforeRelease['aktiv'] === 1
                && $raceBeforeRelease['sid']
                    === 'access-shift-concurrent-login',
            'deactivation bypassed the in-flight login account lock'
        );
        estab_user_admin_release_account_lock($loginConnection, $loginLock);
        $loginLock = '';
        $workerOutput = stream_get_contents($workerPipes[1]);
        $workerError = stream_get_contents($workerPipes[2]);
        fclose($workerPipes[1]);
        fclose($workerPipes[2]);
        $workerPipes = [];
        $workerExit = proc_close($worker);
        $worker = null;
        $workerDecoded = json_decode(
            (string) $workerOutput,
            true,
            16,
            JSON_THROW_ON_ERROR
        );
        $raceAfterRelease = $accountRow($connection, 'rac001');
        $assert(
            $workerExit === 0
                && $workerError === ''
                && ($workerDecoded['revoked_accounts'] ?? null) === ['rac001']
                && (int) $raceAfterRelease['aktiv'] === 0
                && $raceAfterRelease['sid'] === '',
            'deactivation did not atomically follow and revoke the completed login'
        );
        $staleRaceSession = [
            'vStab_benutzer' => 'Parallel angemeldet',
            'vStab_kuerzel' => 'rac001',
            'vStab_funktion' => 'S3',
            'vStab_rolle' => 'Stab',
        ];
        $assert(
            estab_auth_current_session_identity(
                $staleRaceSession,
                $databaseConfig,
                'nv_benutzer',
                'access-shift-concurrent-login'
            ) === null
                && $staleRaceSession === ['menue' => 'LOGIN'],
            'session validation accepted the SID revoked after deactivation'
        );
    } finally {
        if ($loginLock !== '') {
            try {
                estab_user_admin_release_account_lock(
                    $loginConnection,
                    $loginLock
                );
            } catch (Throwable) {
            }
        }
        foreach ($workerPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($worker)) {
            proc_terminate($worker);
            proc_close($worker);
        }
        @unlink($workerReady);
        estab_auth_close($loginConnection);
    }

    estab_shift_access_add_member(
        $connection,
        $incidentId,
        $enabledBId,
        'blk001',
        'shift-access-integration'
    );
    $connection->query(
        "UPDATE `nv_benutzer` SET `estab_gesperrt` = 1, `aktiv` = 0, `sid` = ''"
        . " WHERE BINARY `kuerzel` = BINARY 'blk001'"
    );
    $blocked = $accountRow($connection, 'blk001');
    $assert(
        estab_auth_shift_access_allowed($connection, 'blk001')
            && (int) $blocked['estab_gesperrt'] === 1,
        'access-shift enablement unexpectedly removed the manual block'
    );
    $expectPermissionDenial(
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $identityFor($blocked)
        ),
        'a manually blocked account gained access through an enabled shift'
    );

    $eventCount = (int) $connection->query(
        "SELECT COUNT(*) FROM `nv_betriebsereignisse`"
        . " WHERE `einsatz_id` = {$incidentId}"
        . " AND `objekttyp` = 'ZUGANGSSCHICHT'"
    )->fetch_row()[0];
    $chain = estab_dv_verify_event_chain($connection, $incidentId);
    $assert(
        $eventCount >= 8
            && ($chain['valid'] ?? false) === true
            && (int) ($chain['events'] ?? 0) >= $eventCount,
        'the ZUGANGSSCHICHT event history is missing or its hash chain is invalid'
    );

    $status = estab_incident_status($connection);
    estab_incident_deactivate(
        $connection,
        $incidentId,
        (int) $status['revision'],
        'shift-access-integration'
    );
    $expectPermissionDenial(
        static fn (): array => estab_dv_require_operational_account(
            $connection,
            $incidentId,
            $identityFor($unassigned)
        ),
        'an operational write remained available without an active incident'
    );

    echo "Access-shift integration passed ({$assertions} assertions).\n";
} finally {
    estab_auth_close($connection);
}
