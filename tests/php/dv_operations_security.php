<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/operational_guard.php';
require_once __DIR__ . '/../../app/shift_access.php';

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
$read = static function (string $relative): string {
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $source;
};
$slice = static function (
    string $source,
    string $startMarker,
    string $endMarker
): string {
    $start = strpos($source, $startMarker);
    $end = strpos(
        $source,
        $endMarker,
        $start === false ? 0 : $start + strlen($startMarker)
    );
    if (!is_int($start) || !is_int($end) || $end <= $start) {
        throw new RuntimeException('Could not isolate source boundary');
    }
    return substr($source, $start, $end - $start);
};

/* Request-wide writes remain fail-closed and incident-bound. */
$unknown = [
    'REQUEST_METHOD' => 'POST',
    'SCRIPT_NAME' => '/4fach/future_operational_endpoint.php',
];
$assert(
    estab_operational_write_request($unknown)
        && estab_operational_control_exception($unknown, ['action' => 'save'])
            === null,
    'unknown authenticated POST endpoint is not closed by default'
);
$assert(
    estab_operational_control_exception(
        [
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/4fadm/incidents.php',
        ],
        ['incident_action' => 'close']
    ) === 'administration',
    'administrative incident control is not an explicit exception'
);
$assert(
    estab_operational_control_exception(
        [
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/4fach/logout.php',
        ],
        ['logout_action' => 'logout']
    ) === 'logout',
    'logout is not an explicit control exception'
);
$assert(
    estab_operational_control_exception(
        [
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/4fach/activity.php',
        ],
        ['csrf_token' => str_repeat('a', 64)]
    ) === 'session-activity',
    'session activity is not an explicit non-operational exception'
);
foreach (ESTAB_OPERATIONAL_MESSENGER_LIFECYCLE_ACTIONS as $transition) {
    $assert(
        estab_operational_control_exception(
            [
                'REQUEST_METHOD' => 'POST',
                'SCRIPT_NAME' => '/4fach/fuehrungsstelle.php',
            ],
            [
                'operation_action' => 'messenger_transition',
                'transition' => $transition,
            ]
        ) === 'messenger-lifecycle',
        'personal messenger transition is blocked: ' . $transition
    );
}
foreach (['accept_hat', 'select_hat', 'confirm_handover'] as $retiredAction) {
    $assert(
        estab_operational_control_exception(
            [
                'REQUEST_METHOD' => 'POST',
                'SCRIPT_NAME' => '/4fach/fuehrungsstelle.php',
            ],
            ['operation_action' => $retiredAction]
        ) === null,
        'retired duty-assignment action still bypasses the write guard: '
            . $retiredAction
    );
}
$assert(
    estab_operational_control_exception(
        [
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/4fach/anhang.php',
        ],
        ['absenden_x' => '1']
    ) === null
        && estab_operational_control_exception(
            [
                'REQUEST_METHOD' => 'POST',
                'SCRIPT_NAME' => '/4fach/anhang.php',
            ],
            ['abbrechen_x' => '1']
        ) === 'attachment-cleanup',
    'attachment upload and cleanup are not separated fail-closed'
);
foreach (
    [
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/4fadm/incidents.php',
            'SCRIPT_NAME' => '/4fach/mainindex.php',
        ],
        [
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/4fach/mainindex.php/4fadm',
            'PHP_SELF' => '/4fach/mainindex.php/4fadm',
        ],
        [
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/4fach/mainindex.php',
            'PATH_INFO' => '/4fadm',
        ],
    ] as $pathBypass
) {
    $assert(
        estab_operational_control_exception($pathBypass, ['save' => '1'])
            === null,
        'attacker-controlled path was accepted as an admin boundary'
    );
}

$dv = $read('app/dv_operations.php');
$auth = $read('app/auth.php');
$shiftAccess = $read('app/shift_access.php');
$attachment = $read('app/attachment.php');
$operationalGuard = $read('app/operational_guard.php');
$databaseConfig = $read('4fcfg/dbcfg.inc.php');
$csrf = $read('app/csrf.php');
$logbook = $read('app/logbook.php');
$logbookLifecycle = $read('app/logbook_lifecycle.php');
$operationsUi = $read('4fach/fuehrungsstelle.php');
$adminUi = $read('4fadm/fuehrungsstelle.php');
$messageController = $read('4fach/data_hndl.php');
$etb = $read('stabetb/etb.php');
$tbb = $read('fmtbb/tbb.php');

$assert(
    str_contains(
        $databaseConfig,
        "require_once __DIR__ . '/../app/operational_guard.php'"
    )
        && str_contains($databaseConfig, 'estab_operational_write_enforce(')
        && str_contains(
            $operationalGuard,
            'Operative Eingaben sind gesperrt, weil kein Einsatz aktiv'
        )
        && str_contains(
            $operationalGuard,
            'estab_incident_command_post_name($incident);'
        ),
    'common write boundary does not require an active, configured incident'
);
$assert(
    str_contains(
        $csrf,
        'final class EstabCsrfException extends RuntimeException'
    )
        && str_contains($operationsUi, 'estab_csrf_require_post($_SERVER, $_POST);')
        && str_contains($adminUi, 'estab_csrf_require_post($_SERVER, $_POST);')
        && str_contains($operationsUi, '} catch (EstabCsrfException) {')
        && str_contains($adminUi, '} catch (EstabCsrfException) {'),
    'operator/admin shift endpoints lack explicit CSRF handling'
);

/* Fixed account identity is the only operational authorization source. */
$accountGuard = $slice(
    $dv,
    'function estab_dv_require_operational_account(',
    'function estab_dv_require_active_capability_for_operational_write('
);
$assert(
    str_contains($accountGuard, 'FROM `nv_benutzer` AS account')
        && str_contains($accountGuard, 'JOIN `nv_einsatz_status` AS active_incident')
        && str_contains($accountGuard, "incident.`estab_status` = 'open'")
        && str_contains($accountGuard, 'BINARY account.`benutzer` = BINARY ?')
        && str_contains($accountGuard, 'BINARY account.`kuerzel` = BINARY ?')
        && str_contains($accountGuard, 'BINARY account.`funktion` = BINARY ?')
        && str_contains($accountGuard, 'BINARY account.`rolle` = BINARY ?')
        && str_contains($accountGuard, 'account.`aktiv` = 1')
        && str_contains($accountGuard, 'account.`estab_gesperrt` = 0')
        && str_contains($accountGuard, 'LIMIT 1 FOR UPDATE'),
    'operational writes are not bound to the exact active account and incident'
);
$assert(
    str_contains($accountGuard, 'estab_auth_shift_access_allowed(')
        && str_contains($accountGuard, '$requireMessengerAvailable')
        && !str_contains($accountGuard, 'duty_assignment_id')
        && !str_contains($accountGuard, 'nv_dienstbesetzungen')
        && !str_contains($accountGuard, 'nv_dienstschichten'),
    'account guard still requires a formal duty shift or ignores group access'
);
$capabilityGuard = $slice(
    $dv,
    'function estab_dv_require_account_capability(',
    'function estab_dv_has_account_capability('
);
$assert(
    str_contains($capabilityGuard, 'estab_dv_require_operational_account(')
        && str_contains($capabilityGuard, 'FROM `nv_funktionsfaehigkeiten` AS capability')
        && str_contains($capabilityGuard, 'capability.`funktion` = BINARY ?')
        && str_contains($capabilityGuard, 'capability.`rolle` = BINARY ?')
        && str_contains($capabilityGuard, 'capability.`faehigkeit` = BINARY ?')
        && !str_contains($capabilityGuard, 'dienstbesetzung'),
    'capability enforcement is not derived exclusively from the account tuple'
);
$assert(
    !str_contains($dv, 'function estab_dv_select_session_hat(')
        && !str_contains($dv, "\$session['estab_duty_assignment_id'] =")
        && !str_contains($dv, "\$session['vStab_funktion'] ="),
    'retired shift selection API can still mutate session function or role'
);
$assert(
    str_contains($auth, 'unset($session[\'estab_duty_assignment_id\']);')
        && str_contains($auth, 'estab_auth_shift_access_allowed(')
        && str_contains($auth, "\$storedUser['funktion'] ?? ''")
        && str_contains($auth, "\$storedUser['rolle'] ?? ''")
        && !str_contains($auth, "'duty_assignment_id' => \$dutyAssignmentId"),
    'runtime authentication still trusts a shift-derived role or assignment id'
);
$assert(
    str_contains($messageController, 'estab_auth_shift_access_allowed (')
        && str_contains($messageController, '$login ["funktion"]')
        && str_contains(
            $messageController,
            'estab_auth_assignment_allowed ($dbUser, $login ["funktion"])'
        )
        && !str_contains(
            $messageController,
            '$_SESSION ["estab_duty_assignment_id"] ='
        ),
    'login does not enforce fixed function and optional group-access state'
);

/* Optional access shifts: no membership means allowed, otherwise OR semantics. */
$assert(
    estab_shift_access_positive_id('17', 'Schicht') === 17
        && estab_shift_access_code(' AW_1 ') === 'aw_1'
        && estab_shift_access_label(' Nachtschicht ') === 'Nachtschicht'
        && estab_shift_access_optional_datetime(
            '2026-08-01T20:15',
            'Beginn'
        ) === '2026-08-01 20:15:00'
        && estab_shift_access_optional_datetime('', 'Ende') === null,
    'access-shift input normalization is inconsistent'
);
foreach (
    [
        static fn (): mixed => estab_shift_access_positive_id('01', 'Schicht'),
        static fn (): mixed => estab_shift_access_code('../root'),
        static fn (): mixed => estab_shift_access_label("bad\0label"),
        static fn (): mixed => estab_shift_access_optional_datetime(
            '2026-02-30T20:15',
            'Beginn'
        ),
    ] as $invalidInput
) {
    try {
        $invalidInput();
        $assert(false, 'invalid access-shift input was accepted');
    } catch (EstabShiftAccessInputException) {
        $assert(true, 'invalid access-shift input rejected');
    }
}
$authShiftGate = $slice(
    $auth,
    'function estab_auth_shift_access_state(',
    'function estab_auth_shift_access_allowed('
);
$assert(
    str_contains($authShiftGate, 'membership.`entfernt_am` IS NULL')
        && str_contains($authShiftGate, 'access_shift.`zugang_aktiv` = 1')
        && str_contains(
            $authShiftGate,
            "'allowed' => \$memberships === 0 || \$activeMemberships > 0"
        )
        && !str_contains($authShiftGate, 'funktion')
        && !str_contains($authShiftGate, 'rolle'),
    'optional shift gate lacks unmanaged-account or any-active-membership semantics'
);
$stateDomain = $slice(
    $shiftAccess,
    'function estab_shift_access_user_state(',
    'function estab_shift_access_revoke_if_denied('
);
$assert(
    str_contains($stateDomain, '$allowed = $memberships === [];')
        && str_contains($stateDomain, "['zugang_aktiv'] ?? 0")
        && str_contains($stateDomain, '$allowed = true;')
        && str_contains($stateDomain, "'managed' => \$memberships !== []"),
    'domain access state does not implement optional OR membership semantics'
);
$assert(
    str_contains($shiftAccess, 'function estab_shift_access_create(')
        && str_contains($shiftAccess, 'function estab_shift_access_add_member(')
        && str_contains($shiftAccess, 'function estab_shift_access_remove_member(')
        && str_contains($shiftAccess, 'function estab_shift_access_set_enabled(')
        && str_contains(
            $shiftAccess,
            'function estab_shift_access_confirmation_version('
        )
        && str_contains($shiftAccess, 'hash_equals(')
        && str_contains($shiftAccess, '$expectedMembershipId')
        && str_contains($adminUi, 'name="expected_confirmation_version"')
        && str_contains($shiftAccess, 'VALUES (?, ?, ?, ?, 0, NOW(6)')
        && str_contains($shiftAccess, 'estab_incident_with_active_write('),
    'access-shift mutations are incomplete, enabled by default, or not incident-bound'
);
$assert(
    str_contains($shiftAccess, 'estab_shift_access_acquire_policy_lock(')
        && str_contains($shiftAccess, 'estab_user_admin_acquire_account_lock(')
        && str_contains($shiftAccess, 'estab_shift_access_release_policy_lock(')
        && str_contains($shiftAccess, 'estab_user_admin_release_account_lock(')
        && str_contains($shiftAccess, 'estab_shift_access_audit('),
    'concurrent access toggles lack serialization or audit evidence'
);
$protocolDetails = estab_shift_access_protocol_details([
    'shift_id' => 7,
    'members' => array_fill(0, 20000, 'aw0001'),
    'revoked_accounts' => array_fill(0, 20000, 'aw0001'),
]);
$protocolJson = json_encode(
    $protocolDetails,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
$assert(
    !array_key_exists('members', $protocolDetails)
        && !array_key_exists('revoked_accounts', $protocolDetails)
        && ($protocolDetails['members_count'] ?? null) === 20000
        && ($protocolDetails['revoked_accounts_count'] ?? null) === 20000
        && count($protocolDetails['members_sample'] ?? []) === 10
        && preg_match(
            '/\A[0-9a-f]{64}\z/D',
            (string) ($protocolDetails['members_sha256'] ?? '')
        ) === 1
        && strlen($protocolJson) < 2048,
    'large access-shift rosters can overflow the legacy TEXT audit column'
);
$addMemberDomain = $slice(
    $shiftAccess,
    'function estab_shift_access_add_member(',
    'function estab_shift_access_remove_member('
);
$assert(
    str_contains($addMemberDomain, 'INSERT INTO `nv_zugangsschicht_mitglieder`')
        && str_contains($addMemberDomain, '`entfernt_am` IS NULL LIMIT 1 FOR UPDATE')
        && !str_contains(
            $addMemberDomain,
            'UPDATE `nv_zugangsschicht_mitglieder`'
        ),
    'membership re-addition overwrites a historical assignment interval'
);
$revocation = $slice(
    $shiftAccess,
    'function estab_shift_access_revoke_if_denied(',
    'function estab_shift_access_list('
);
$assert(
    str_contains($revocation, "if (\$state['allowed'])")
        && str_contains(
            $revocation,
            "UPDATE `nv_benutzer` SET `aktiv` = 0, `sid` = '', `ip` = ''"
        )
        && str_contains($revocation, "`fwdip` = '' WHERE BINARY `kuerzel`")
        && !str_contains($revocation, '`estab_gesperrt` =')
        && !str_contains($revocation, '`funktion` =')
        && !str_contains($revocation, '`rolle` =')
        && !str_contains($revocation, '`passwort` ='),
    'group deactivation mutates durable account rights instead of only revoking sessions'
);
$assert(
    !str_contains($shiftAccess, 'UPDATE `nv_benutzer` SET `funktion`')
        && !str_contains($shiftAccess, 'UPDATE `nv_benutzer` SET `rolle`')
        && !str_contains($shiftAccess, 'INSERT INTO `nv_funktionsfaehigkeiten`')
        && !str_contains($shiftAccess, 'estab_dv_select_session_hat('),
    'access-shift administration can grant or replace a fachliche role'
);

/* The administration explains and exposes the simpler model. */
$assert(
    str_contains($adminUi, '<h1>Optionale Schichten</h1>')
        && str_contains($adminUi, 'Schichten steuern ausschließlich den Zugang.')
        && str_contains($adminUi, 'Fachrechte stammen immer aus der festen Funktion')
        && str_contains($adminUi, 'eine aktive')
        && str_contains($adminUi, 'Schicht ist dafür niemals erforderlich')
        && str_contains($adminUi, 'Ohne Zuordnung behält ein')
        && str_contains($adminUi, 'mindestens eine seiner Schichten aktiv ist'),
    'optional-shift behaviour is not explained unambiguously in the UI'
);
foreach (['create_shift', 'add_member', 'remove_member', 'set_enabled'] as $action) {
    $assert(
        str_contains($adminUi, "'" . $action . "'")
            || str_contains($adminUi, 'value="' . $action . '"'),
        'access-shift UI lacks action: ' . $action
    );
}
$assert(
    str_contains($adminUi, 'Feste Funktion')
        && str_contains($adminUi, 'Individuell gesperrt')
        && str_contains($adminUi, 'Durch Schichtplanung deaktiviert')
        && str_contains($adminUi, 'wird möglich; niemand wird automatisch angemeldet')
        && str_contains($adminUi, 'Zugang deaktivieren prüfen')
        && str_contains($adminUi, 'Laufende Sitzungen werden sofort beendet für:')
        && str_contains($adminUi, 'Zuordnung entfernen prüfen')
        && str_contains($adminUi, 'Zuordnung jetzt entfernen')
        && str_contains($adminUi, 'der Zugang bleibt gesperrt')
        && str_contains($adminUi, '$overviewLoaded = false;')
        && str_contains($adminUi, 'Schichtübersicht nicht geladen.')
        && str_contains($adminUi, 'Kein zusätzlich zugangsberechtigtes Konto.')
        && str_contains($adminUi, 'Bereits individuell gesperrt')
        && str_contains($adminUi, 'class="estab-visually-hidden"')
        && str_contains($adminUi, '<th scope="row"')
        && str_contains($adminUi, 'name="confirm_assignment"'),
    'admin overview conflates account block, function and group access'
);

/* Operational UIs and ETB/TBB do not depend on a formal duty assignment. */
$assert(
    str_contains($operationsUi, 'estab_read_require_operational_scope(')
        && str_contains($operationsUi, 'estab_dv_has_account_capability(')
        && !str_contains($operationsUi, 'duty_assignment_id')
        && !str_contains($operationsUi, "'accept_hat'")
        && !str_contains($operationsUi, "'select_hat'")
        && !str_contains($operationsUi, "'confirm_handover'"),
    'operator command-post UI still requires or switches a duty assignment'
);
$assert(
    str_contains($dv, 'function estab_dv_messenger_candidates(')
        && str_contains($dv, "BINARY u.`funktion` = BINARY 'A/W'")
        && str_contains($dv, "BINARY u.`rolle` = BINARY 'Fernmelder'")
        && str_contains($dv, 'estab_auth_shift_access_allowed(')
        && str_contains($operationsUi, '$users = estab_dv_messenger_candidates('),
    'messenger candidates are not fixed A/W accounts with effective access'
);
$messengerAssign = $slice(
    $dv,
    'function estab_dv_assign_messenger(',
    'function estab_dv_transition_messenger('
);
$assert(
    str_contains($messengerAssign, "'FERNMELDEBETRIEB'")
        && str_contains($messengerAssign, "u.`funktion` = BINARY 'A/W'")
        && str_contains($messengerAssign, "u.`rolle` = BINARY 'Fernmelder'")
        && str_contains($messengerAssign, 'estab_auth_shift_access_allowed(')
        && !str_contains($messengerAssign, 'nv_dienstbesetzungen')
        && !str_contains($messengerAssign, 'nv_dienstschichten'),
    'messenger dispatch still derives authority or candidates from duty shifts'
);
$generalWriteGuard = $slice(
    $dv,
    'function estab_dv_require_active_capability_for_operational_write(',
    'function estab_dv_require_account_capability('
);
$messengerTransition = $slice(
    $dv,
    'function estab_dv_transition_messenger(',
    'function estab_dv_verify_messenger_snapshots('
);
$assert(
    str_contains(
        $generalWriteGuard,
        "\$shape = estab_dv_require_operational_account("
    )
        && preg_match(
            '/estab_dv_require_operational_account\(\s*'
                . '\$connection,\s*\$incidentId,\s*\$identity,\s*true\s*\)/s',
            $generalWriteGuard
        ) === 1
        && preg_match(
            '/estab_dv_require_account_capability\(\s*'
                . '\$connection,\s*\$incidentId,\s*\$identity,\s*'
                . '\$requiredCapability,\s*false\s*\)/s',
            $messengerTransition
        ) === 1
        && str_contains(
            $messengerTransition,
            "['accept', 'deliver', 'return_path', 'returned']"
        )
        && str_contains(
            $messengerTransition,
            "hash_equals((string) \$row['melder_kuerzel'], \$actorCode)"
        ),
    'general writes do not block an away messenger or personal lifecycle cannot progress'
);
$assert(
    str_contains(
        $logbook,
        'estab_dv_require_active_capability_for_operational_write('
    )
        && str_contains($logbook, "'EINSATZTAGEBUCH'")
        && str_contains($logbook, "'BEFOERDERUNG'")
        && str_contains($logbook, "'shift_id' => null")
        && str_contains($logbook, "'writer_assignment_id' => null")
        && str_contains($logbook, "in_array(\$function, ['ETB', 'S2'], true)")
        && str_contains($logbook, "\$function === 'A/W'"),
    'ETB/TBB authorship is not capability-based and shift-independent'
);
$assert(
    !str_contains(
        $logbookLifecycle,
        'function estab_logbook_lifecycle_active_shift_id('
    )
        && substr_count($logbookLifecycle, '?int $shiftId = null') >= 3
        && str_contains($logbookLifecycle, '`estab_shift_id`')
        && !str_contains(
            $logbookLifecycle,
            'Eine aktive Dienstschicht ist erforderlich'
        ),
    'logbook lifecycle still requires an active formal duty shift'
);
foreach (['ETB' => $etb, 'TBB' => $tbb] as $name => $source) {
    $scope = strpos($source, 'estab_read_require_operational_scope (');
    $constructor = strpos($source, 'new ' . strtolower($name) . '_liste');
    $assert(
        is_int($scope)
            && is_int($constructor)
            && $scope < $constructor
            && str_contains($source, 'estab_dv_has_account_capability (')
            && !str_contains($source, 'duty_assignment_id'),
        $name . ' does not enforce fixed account/capability before data access'
    );
}

/* Attachments keep the same incident/account/capability boundary. */
$assert(
    str_contains($attachment, 'estab_attachment_require_operational_identity(')
        && substr_count(
            $attachment,
            'estab_attachment_require_operational_identity('
        ) >= 5
        && str_contains($attachment, 'complete fixed account identity')
        && !str_contains($attachment, "'duty_assignment_id' =>"),
    'attachment domain is not fixed-account and incident scoped'
);

echo "DV operations security: OK ({$assertions} assertions)\n";
