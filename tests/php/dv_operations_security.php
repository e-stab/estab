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
foreach ([
    'accept_hat' => 'duty-accept',
    'select_hat' => 'duty-select',
    'confirm_handover' => 'handover-confirm',
] as $dutyAction => $exception) {
    $assert(
        estab_operational_control_exception(
            [
                'REQUEST_METHOD' => 'POST',
                'SCRIPT_NAME' => '/4fach/fuehrungsstelle.php',
            ],
            ['operation_action' => $dutyAction]
        ) === $exception,
        'STRICT duty-assignment action is not an explicit bootstrap exception: '
            . $dutyAction
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
$messengerUi = $read('4fach/melderauftraege.php');
$adminUi = $read('4fadm/fuehrungsstelle.php');
$browserAcceptance = $read('tests/browser/headless_ui.py');
$integrationCi = $read('tests/integration/ci.sh');
$integration = $read('tests/integration/dv_operations.php');
$inactiveMessengerFixture = $read(
    'tests/integration/inactive_messenger_browser_fixture.php'
);
$messageController = $read('4fach/data_hndl.php');
$messageRepository = $read('app/message_repository.php');
$etb = $read('stabetb/etb.php');
$tbb = $read('fmtbb/tbb.php');
$acceptHat = $slice(
    $dv,
    'function estab_dv_accept_hat(',
    'function estab_dv_shift_required_hats('
);
$confirmHandover = $slice(
    $dv,
    'function estab_dv_confirm_handover_shift(',
    'function estab_dv_close_shift('
);

$assert(
    substr_count(
        $acceptHat,
        'estab_incident_duty_shift_required('
    ) >= 2
        && strpos(
            $acceptHat,
            'estab_incident_duty_shift_required($modeIncident)'
        ) < strpos($acceptHat, 'estab_dv_prepare_assignment_schema(')
        && strpos(
            $acceptHat,
            'estab_incident_with_active_write('
        ) < strrpos(
            $acceptHat,
            'estab_incident_duty_shift_required($incident)'
        )
        && str_contains(
            $acceptHat,
            'Formale Dienstfunktionen können nur im strengen'
        )
        && str_contains(
            $confirmHandover,
            '!estab_incident_duty_shift_required($incident)'
        )
        && strpos(
            $confirmHandover,
            '!estab_incident_duty_shift_required($incident)'
        ) < strpos(
            $confirmHandover,
            "'SELECT `von_dienstschicht_id`, `an_dienstschicht_id`,"
        ),
    'formal duty acceptance or handover remains reachable outside a locked STRICT incident snapshot'
);

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
        $operationalGuard,
        'function estab_operational_redirect_stale_message_post('
    )
        && str_contains(
            $operationalGuard,
            'estab_workflow_anonymous_operational_post('
        )
        && str_contains(
            $operationalGuard,
            "'/4fach/mainindex.php'"
        )
        && str_contains(
            $operationalGuard,
            "estab_navigation_login_content_url(\n"
                . "            'messages',\n"
                . '            true'
        )
        && str_contains($operationalGuard, 'true,' . "\n" . '        303')
        && str_contains($databaseConfig, "    \$_POST,\n    \$_GET\n"),
    'stale same-site message POST does not discard data into the login flow'
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

/* STRICT uses one selected hat; LOOSE uses the account plus personal grants. */
$accountGuard = $slice(
    $dv,
    'function estab_dv_require_operational_account(',
    'function estab_dv_require_active_capability_for_operational_write('
);
$activeHatGuard = $slice(
    $dv,
    'function estab_dv_require_active_hat_for_operational_write(',
    'function estab_dv_require_operational_account('
);
$assert(
    str_contains($accountGuard, 'FROM `nv_benutzer` AS account')
        && str_contains(
            $accountGuard,
            'SELECT account.`funktion`, account.`rolle`'
        )
        && str_contains($accountGuard, 'JOIN `nv_einsatz_status` AS active_incident')
        && str_contains($accountGuard, "incident.`estab_status` = 'open'")
        && str_contains($accountGuard, 'BINARY account.`benutzer` = BINARY ?')
        && str_contains($accountGuard, 'BINARY account.`kuerzel` = BINARY ?')
        && str_contains($accountGuard, 'account.`aktiv` = 1')
        && str_contains($accountGuard, 'account.`estab_gesperrt` = 0')
        && str_contains($accountGuard, '$provenanceFunction')
        && str_contains($accountGuard, '$provenanceRole')
        && str_contains(
            $accountGuard,
            'estab_auth_identity_has_function('
        )
        && str_contains($accountGuard, 'LIMIT 1 FOR UPDATE'),
    'operational writes are not bound to the authoritative account, acting function and incident'
);
$assert(
    str_contains(
        $accountGuard,
        'estab_incident_duty_shift_required($incident)'
    )
        && str_contains(
            $accountGuard,
            'estab_dv_require_active_hat_for_operational_write('
        )
        && str_contains($accountGuard, "'duty_assignment_id'")
        && str_contains($accountGuard, "'estab_permission_mode' => 'STRICT'")
        && str_contains($activeHatGuard, '`nv_dienstbesetzungen`')
        && str_contains($activeHatGuard, '`nv_dienstschichten`')
        && str_contains($activeHatGuard, "duty_shift.`status` = 'AKTIV'")
        && str_contains($activeHatGuard, "assignment.`status` = 'ANGENOMMEN'")
        && str_contains($accountGuard, 'estab_auth_shift_access_allowed(')
        && str_contains($accountGuard, 'estab_auth_fetch_additional_functions(')
        && str_contains($accountGuard, '$requireMessengerAvailable')
        && str_contains($accountGuard, "'estab_permission_mode'] = 'LOOSE'"),
    'STRICT selected-hat and LOOSE account/grant/access boundaries are not separated'
);
$assert(
    str_contains(
        $auth,
        'function estab_auth_function_role_is_current('
    )
        && str_contains(
            $auth,
            "\$mode === 'STRICT'"
        )
        && substr_count(
            $accountGuard,
            'estab_auth_function_role_is_current('
        ) === 1
        && !str_contains(
            $accountGuard,
            'Die ausgewählte Dienstfunktion gehört nicht mehr zum'
        )
        && str_contains(
            $accountGuard,
            'Die feste Kontofunktion gehört nicht mehr zum freigegebenen'
        ),
    'STRICT selected-hat validation drifted or LOOSE accepts stale tuples'
);
$sqlAuthority = $slice(
    $dv,
    'function estab_dv_authority_assignment_id(',
    'function estab_dv_has_write_capability('
);
$assert(
    str_contains(
        $sqlAuthority,
        'SELECT @estab_dv_actor_assignment_id AS `actor_assignment_id`'
    )
        && str_contains(
            $sqlAuthority,
            '@estab_dv_target_assignment_id AS `target_assignment_id`'
        )
        && str_contains(
            $sqlAuthority,
            'Ein verschachtelter oder verbliebener'
        )
        && str_contains($sqlAuthority, '} finally {')
        && substr_count(
            $sqlAuthority,
            'SET @estab_dv_actor_assignment_id = NULL'
        ) >= 2
        && substr_count(
            $dv,
            'estab_dv_with_sql_authority_context('
        ) === 6
        && str_contains($dv, "'actor_permission_mode' =>")
        && str_contains($dv, "'actor_duty_assignment_id' =>"),
    'exact SQL assignment context can nest, leak or lose audit provenance'
);
$messageStageActor = $slice(
    $messageRepository,
    'function estab_message_require_operator_stage_actor(',
    'function estab_message_acquire_operator_stage_lock('
);
$messageAcquireLock = $slice(
    $messageRepository,
    'function estab_message_acquire_operator_stage_lock(',
    'function estab_message_fetch_locked_operator_stage('
);
$messageUpdateLock = $slice(
    $messageRepository,
    'function estab_message_update_locked_operator_stage(',
    'function estab_message_release_operator_stage_lock('
);
$messageReleaseLock = $slice(
    $messageRepository,
    'function estab_message_release_operator_stage_lock(',
    'function estab_message_update_pending_review('
);
$messageStateSet = $slice(
    $messageRepository,
    'function estab_message_state_set_for_recipient(',
    'function estab_message_state_unset_for_recipient('
);
$messageStateUnset = $slice(
    $messageRepository,
    'function estab_message_state_unset_for_recipient(',
    'function estab_message_state_ids('
);
$assert(
    str_contains($messageStageActor, 'array $actor')
        && str_contains($messageStageActor, 'estab_dv_require_write_capability(')
        && str_contains($messageStageActor, "'FERNMELDEBETRIEB'")
        && str_contains($messageStageActor, "'BEFOERDERUNG'")
        && str_contains($messageStageActor, "\$requiredFunction = \$status === ESTAB_MESSAGE_STATUS_LDF ? 'LdF' : 'A/W'")
        && str_contains($messageStageActor, "'Fernmelder'")
        && !str_contains(
            $messageStageActor,
            'estab_incident_role_permissions_enforced'
        ),
    'shared operator-stage guard does not require the exact effective capability'
);
foreach (
    [
        'operator lock acquisition' => $messageAcquireLock,
        'operator stage update' => $messageUpdateLock,
        'operator lock release' => $messageReleaseLock,
    ] as $boundary => $source
) {
    $assert(
        str_contains($source, 'array $actor')
            && str_contains(
                $source,
                'estab_message_require_operator_stage_actor('
            ),
        $boundary . ' bypasses the transactional operator-stage actor guard'
    );
}
foreach (
    [
        'recipient state set' => $messageStateSet,
        'recipient state unset' => $messageStateUnset,
    ] as $boundary => $source
) {
    $assert(
        str_contains($source, 'array $actor')
            && preg_match(
                '/\$incidentId\s*=.*?;\s*'
                    . '\$operationalActor\s*=\s*'
                    . 'estab_dv_require_operational_account\(\s*'
                    . '\$connection,\s*\$incidentId,\s*\$actor\s*\)/s',
                $source
            ) === 1,
        $boundary . ' does not revalidate the exact account inside its write'
    );
}
$assert(
    str_contains($messageAcquireLock, '$operationalActor' . "['kuerzel']")
        && str_contains($messageUpdateLock, '$operationalActor' . "['kuerzel']")
        && str_contains($messageReleaseLock, '$operationalActor' . "['kuerzel']")
        && str_contains($messageReleaseLock, 'bool $force = false')
        && str_contains(
            $messageStateSet,
            "\$function = (string) (\$operationalActor['funktion'] ?? '');"
        )
        && str_contains(
            $messageStateUnset,
            "\$function = (string) (\$operationalActor['funktion'] ?? '');"
        )
        && str_contains(
            $messageStateSet,
            "\$role = (string) (\$operationalActor['rolle'] ?? '');"
        )
        && str_contains(
            $messageStateUnset,
            "\$role = (string) (\$operationalActor['rolle'] ?? '');"
        )
        && str_contains(
            $messageStateSet,
            'estab_message_recipient_pattern($function)'
        )
        && str_contains(
            $messageStateUnset,
            'estab_message_recipient_pattern($function)'
        )
        && !str_contains(
            $messageStateSet,
            'estab_message_effective_staff_functions('
        )
        && !str_contains(
            $messageStateUnset,
            'estab_message_effective_staff_functions('
        ),
    'message lock/state authority is not bound to the revalidated exact acting function'
);
$effectiveCapability = $slice(
    $dv,
    'function estab_dv_effective_identity_capability_function(',
    'function estab_dv_effective_identity_has_capability('
);
$capabilityGuard = $slice(
    $dv,
    'function estab_dv_require_account_capability(',
    'function estab_dv_has_account_capability('
);
$writeCapabilityGuard = $slice(
    $dv,
    'function estab_dv_require_write_capability(',
    'function estab_dv_authority_assignment_id('
);
$assert(
    str_contains($capabilityGuard, 'estab_dv_require_operational_account(')
        && str_contains(
            $capabilityGuard,
            'estab_dv_effective_identity_capability_function('
        )
        && str_contains(
            $writeCapabilityGuard,
            'estab_dv_effective_identity_capability_function('
        )
        && str_contains(
            $capabilityGuard,
            'Die aktuell ausgewählte Dienstfunktion besitzt nicht die'
        )
        && str_contains(
            $writeCapabilityGuard,
            'Die aktuell ausgewählte Dienstfunktion besitzt nicht die'
        )
        && str_contains($effectiveCapability, '`nv_funktionsfaehigkeiten`')
        && str_contains($effectiveCapability, '`funktion` = BINARY ?')
        && str_contains($effectiveCapability, '`rolle` = BINARY ?')
        && str_contains($effectiveCapability, '`faehigkeit` = BINARY ?')
        && str_contains(
            $effectiveCapability,
            'estab_auth_effective_function_roles($identity)'
        ),
    'STRICT selected-tuple or LOOSE effective-function capability enforcement is incomplete'
);
$assert(
    str_contains($dv, 'function estab_dv_select_session_hat(')
        && str_contains($dv, "\$session['estab_duty_assignment_id'] =")
        && str_contains($dv, "\$session['vStab_funktion'] =")
        && str_contains(
            $dv,
            '!estab_incident_duty_shift_required($incident)'
        ),
    'STRICT shift selection API is absent or available in LOOSE'
);
$assert(
    str_contains($auth, 'estab_auth_duty_assignment_matches_session(')
        && str_contains($auth, 'estab_auth_active_permission_mode(')
        && str_contains($auth, "\$mode === 'LOOSE' && \$dutyAssignmentId !== null")
        && str_contains($auth, "\$mode !== 'LOOSE'")
        && str_contains($auth, 'estab_auth_shift_access_allowed(')
        && str_contains($auth, 'estab_auth_fetch_additional_functions(')
        && str_contains($auth, "\$storedUser['funktion'] ?? ''")
        && str_contains($auth, "\$storedUser['rolle'] ?? ''")
        && str_contains(
            $auth,
            "\$authenticatedIdentity['duty_assignment_id'] = \$dutyAssignmentId"
        ),
    'runtime authentication does not separate selected STRICT hats from LOOSE grants/access shifts'
);
$assert(
    str_contains($messageController, 'estab_auth_shift_access_allowed (')
        && str_contains(
            $messageController,
            'estab_auth_active_permission_mode ($connection)'
        )
        && str_contains(
            $messageController,
            '$loginPermissionMode === ESTAB_PERMISSION_MODE_LOOSE'
        )
        && str_contains($messageController, '$login ["funktion"]')
        && str_contains(
            $messageController,
            'estab_auth_assignment_allowed ($dbUser, $login ["funktion"])'
        )
        && !str_contains(
            $messageController,
            '$_SESSION ["estab_duty_assignment_id"] ='
        ),
    'login does not separate fixed function checks from the LOOSE-only group gate'
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
$accessShiftMutationDomains = [
    'create' => $slice(
        $shiftAccess,
        'function estab_shift_access_create(',
        'function estab_shift_access_add_member('
    ),
    'add member' => $slice(
        $shiftAccess,
        'function estab_shift_access_add_member(',
        'function estab_shift_access_remove_member('
    ),
    'remove member' => $slice(
        $shiftAccess,
        'function estab_shift_access_remove_member(',
        'function estab_shift_access_set_enabled('
    ),
];
$setEnabledStart = strpos(
    $shiftAccess,
    'function estab_shift_access_set_enabled('
);
$accessShiftMutationDomains['set enabled'] = is_int($setEnabledStart)
    ? substr($shiftAccess, $setEnabledStart)
    : '';
foreach ($accessShiftMutationDomains as $mutation => $domain) {
    $writeSnapshot = strpos($domain, 'estab_incident_with_active_write(');
    $looseGuard = strpos(
        $domain,
        'estab_shift_access_require_loose_incident('
    );
    $assert(
        is_int($writeSnapshot)
            && is_int($looseGuard)
            && $writeSnapshot < $looseGuard,
        'access-shift ' . $mutation
            . ' does not require LOOSE inside its locked incident snapshot'
    );
}
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

/* The administration explains and exposes the LOOSE access-shift model. */
$assert(
    str_contains($adminUi, '<h1>Optionale Zugangsschichten</h1>')
        && str_contains(
            $adminUi,
            'Administration · Berechtigungsmodus Locker'
        )
        && str_contains($adminUi, 'Schichten steuern ausschließlich den Zugang.')
        && str_contains(
            $adminUi,
            'Fachrechte stammen aus der festen Primärfunktion des Kontos'
        )
        && str_contains($adminUi, 'persönlichen Zusatzfunktionen')
        && str_contains($adminUi, 'nur')
        && str_contains($adminUi, 'im lockeren Modus')
        && str_contains($adminUi, 'eine aktive')
        && str_contains($adminUi, 'Schicht ist dafür niemals erforderlich')
        && str_contains($adminUi, 'Ohne Zuordnung behält ein')
        && str_contains($adminUi, 'mindestens eine seiner Schichten aktiv ist'),
    'optional-shift behaviour is not explained unambiguously in the UI'
);
foreach ([
    'create_access_shift',
    'add_access_member',
    'remove_access_member',
    'set_access_enabled',
] as $action) {
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

/* Operational UI requires a hat only in STRICT and exposes LOOSE grants. */
$assert(
    str_contains($operationsUi, 'estab_read_require_operational_scope(')
        && str_contains($operationsUi, 'estab_dv_has_write_capability(')
        && str_contains(
            $operationsUi,
            '$strictMode = estab_incident_duty_shift_required($status)'
        )
        && str_contains($operationsUi, 'duty_assignment_id')
        && str_contains($operationsUi, "'accept_hat'")
        && str_contains($operationsUi, "'select_hat'")
        && str_contains($operationsUi, "'confirm_handover'")
        && str_contains(
            $operationsUi,
            '<?php if (!$strictMode || is_array($selectedIdentity)): ?>'
        ),
    'operator command-post UI does not bootstrap STRICT hats or bypass them in LOOSE'
);
$messengerCandidates = $slice(
    $dv,
    'function estab_dv_messenger_candidates(',
    'function estab_dv_messenger_snapshot('
);
$assert(
    str_contains(
        $messengerCandidates,
        'estab_incident_duty_shift_required($incident)'
    )
        && str_contains(
            $messengerCandidates,
            '`nv_dienstbesetzungen` AS assignment'
        )
        && str_contains(
            $messengerCandidates,
            "assignment.`status` = 'ANGENOMMEN'"
        )
        && str_contains(
            $messengerCandidates,
            "BINARY assignment.`funktion` = BINARY 'A/W'"
        )
        && str_contains(
            $messengerCandidates,
            "BINARY assignment.`rolle` = BINARY 'Fernmelder'"
        )
        && str_contains(
            $messengerCandidates,
            'estab_auth_fetch_additional_functions('
        )
        && !str_contains(
            $messengerCandidates,
            '`nv_benutzer_zusatzfunktionen`'
        )
        && str_contains(
            $messengerCandidates,
            "hash_equals('A/W', \$extra['funktion'])"
        )
        && str_contains(
            $messengerCandidates,
            "hash_equals('Fernmelder', \$extra['rolle'])"
        )
        && str_contains(
            $messengerCandidates,
            'estab_auth_shift_access_allowed('
        )
        && str_contains(
            $messengerCandidates,
            'AS `estab_sitzung_vorhanden`'
        )
        && substr_count(
            $messengerCandidates,
            "'^[A-Za-z0-9,-]{1,50}$' THEN 1 ELSE 0 END"
        ) === 2
        && str_contains(
            $messengerCandidates,
            'estab_dv_messenger_presence_details($row)'
        )
        && str_contains(
            $messengerCandidates,
            "\$row['estab_sitzung_vorhanden']"
        )
        && !str_contains($messengerCandidates, 'AND u.`aktiv` = 1')
        && !str_contains($messengerCandidates, "\$row['sid']")
        && str_contains($messengerUi, '$users = estab_dv_messenger_candidates('),
    'messenger candidates do not retain fachliche gates while exposing only '
        . 'server-derived, non-sensitive presence state'
);
$messengerPresence = $slice(
    $dv,
    'function estab_dv_messenger_presence_label(',
    'function estab_dv_messenger_candidates('
);
$assert(
    str_contains($messengerPresence, 'estab_auth_presence_state($account)')
        && str_contains(
            $messengerPresence,
            "'requires_separate_notification' => \$state !== 'online'"
        )
        && str_contains($messengerPresence, "'signed_out' => 'abgemeldet'")
        && str_contains($messengerPresence, "'inactive' => 'inaktiv'"),
    'messenger presence does not derive notification duty on the server'
);
$messengerTarget = $slice(
    $dv,
    'function estab_dv_require_messenger_target(',
    'function estab_dv_assign_messenger('
);
$assert(
    str_contains($messengerTarget, 'estab_incident_duty_shift_required($incident)')
        && str_contains($messengerTarget, '`nv_dienstbesetzungen` AS assignment')
        && str_contains($messengerTarget, "assignment.`status` = 'ANGENOMMEN'")
        && str_contains($messengerTarget, 'estab_auth_fetch_additional_functions(')
        && str_contains($messengerTarget, 'estab_auth_shift_access_allowed(')
        && str_contains(
            $messengerTarget,
            "'permission_mode' => ESTAB_PERMISSION_MODE_STRICT"
        )
        && str_contains(
            $messengerTarget,
            "'permission_mode' => ESTAB_PERMISSION_MODE_LOOSE"
        )
        && str_contains(
            $messengerTarget,
            'estab_dv_messenger_presence_details($row)'
        )
        && !str_contains(
            $messengerTarget,
            "(\$row['aktiv'] ?? 0) !== 1"
        )
        && substr_count(
            $messengerTarget,
            "'^[A-Za-z0-9,-]{1,50}$' THEN 1 ELSE 0 END"
        ) === 2
        && str_contains(
            $integration,
            "\$malformedSessionId = 'invalid session!'"
        ),
    'messenger target is not revalidated against authoritative fachliche '
        . 'gates independently from presence'
);
$messengerAssign = $slice(
    $dv,
    'function estab_dv_assign_messenger(',
    'function estab_dv_transition_messenger('
);
$assert(
    str_contains($messengerAssign, "'FERNMELDEBETRIEB'")
        && str_contains($messengerAssign, 'estab_dv_require_messenger_target(')
        && str_contains(
            $messengerAssign,
            "'actor_function' => (string) \$selected['funktion']"
        )
        && str_contains(
            $messengerAssign,
            "'actor_role' => (string) \$selected['rolle']"
        )
        && str_contains($messengerAssign, "'messenger_function' =>")
        && str_contains($messengerAssign, "'messenger_role' =>")
        && str_contains($messengerAssign, "'messenger_duty_assignment_id' =>")
        && str_contains($messengerAssign, "'permission_mode' =>")
        && str_contains($messengerAssign, "'messenger_presence_state' =>")
        && str_contains(
            $messengerAssign,
            "'separate_notification_required' =>"
        )
        && str_contains($messengerAssign, '?array &$assignmentDetails = null')
        && str_contains(
            $messengerAssign,
            "'requires_separate_notification' =>"
        )
        && substr_count(
            $messengerAssign,
            'estab_dv_with_sql_authority_context('
        ) === 1
        && str_contains(
            $messengerAssign,
            'estab_dv_authority_assignment_id($selected)'
        )
        && str_contains(
            $messengerAssign,
            "'estab_permission_mode' =>"
        )
        && str_contains(
            $messengerAssign,
            "\$messengerAuthority['dienstbesetzung_id']"
        )
        && str_contains(
            $messengerAssign,
            'return (int) $connection->insert_id;'
        )
        && !str_contains(
            $messengerAssign,
            '$jobId = (int) $connection->insert_id;'
        ),
    'messenger dispatch loses actor or target authority provenance'
);
$assert(
    str_contains($messengerUi, 'data-estab-messenger-select')
        && str_contains(
            $messengerUi,
            'data-estab-notification-required'
        )
        && str_contains(
            $messengerUi,
            'data-estab-messenger-presence-warning'
        )
        && str_contains(
            $messengerUi,
            'Der LdF muss ihn separat über den Auftrag informieren.'
        )
        && str_contains($messengerUi, 'Bitte Fernmelder auswählen')
        && str_contains($messengerUi, '$flashWarning =')
        && str_contains(
            $messengerUi,
            'estab-tool-feedback-warning'
        )
        && str_contains($messengerUi, 'Status des Fernmelders: ')
        && str_contains(
            $messengerUi,
            "'messenger_assigned_notification_required'"
        )
        && str_contains($messengerUi, "['presence' => \$presenceState]"),
    'messenger assignment UI does not label presence and preserve the '
        . 'separate-notification warning across PRG'
);
$assert(
    str_contains(
        $integration,
        'signed-out messenger accepted a job without authenticating'
    )
        && str_contains(
            $integration,
            'rejected signed-out acceptance changed the messenger job'
        ),
    'messenger lifecycle does not prove authentication after offline dispatch'
);
$assert(
    str_contains($browserAcceptance, '"--inactive-messenger"')
        && str_contains(
            $browserAcceptance,
            'ESTAB_TEST_INACTIVE_MESSENGER_CODE'
        )
        && str_contains(
            $browserAcceptance,
            'ESTAB_TEST_ONLINE_MESSENGER_CODE'
        )
        && str_contains(
            $browserAcceptance,
            'Bitte Fernmelder auswählen'
        )
        && str_contains(
            $browserAcceptance,
            'messenger_assigned_notification_required'
        )
        && str_contains(
            $browserAcceptance,
            '.estab-tool-feedback-warning'
        )
        && str_contains($browserAcceptance, 'run_inactive_messenger')
        && str_contains($integrationCi, '--inactive-messenger')
        && str_contains(
            $integrationCi,
            'ESTAB_TEST_INACTIVE_MESSENGER_CODE'
        )
        && str_contains(
            $integrationCi,
            'inactive_messenger_browser_fixture.php'
        )
        && str_contains(
            $inactiveMessengerFixture,
            "ESTAB_INACTIVE_MESSENGER_BROWSER_FIXTURE') !== '1'"
        )
        && str_contains(
            $inactiveMessengerFixture,
            "['create', 'cleanup']"
        )
        && str_contains(
            $inactiveMessengerFixture,
            'Refusing inactive-messenger fixture outside a disposable project'
        ),
    'CI does not provision and exercise the inactive-messenger browser mode'
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
            '/estab_dv_require_write_capability\(\s*'
                . '\$connection,\s*\$incidentId,\s*\$identity,\s*'
                . '\$capability,\s*true\s*\)/s',
            $generalWriteGuard
        ) === 1
        && preg_match(
            '/estab_dv_require_operational_account\(\s*'
                . '\$connection,\s*\$incidentId,\s*\$identity,\s*'
                . '\$requireMessengerAvailable,\s*true\s*\)/s',
            $generalWriteGuard
        ) === 1
        && preg_match(
            '/estab_dv_require_write_capability\(\s*'
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
        )
        && str_contains(
            $messengerTransition,
            "'actor_function' => (string) \$selected['funktion']"
        )
        && str_contains(
            $messengerTransition,
            "'actor_role' => (string) \$selected['rolle']"
        ),
    'general writes do not block an away messenger or personal lifecycle cannot progress'
);
$assert(
    str_contains(
        $logbook,
        '$writerIdentity = estab_dv_require_write_capability('
    )
        && str_contains(
            $logbook,
            'estab_logbook_manual_writer_context('
        )
        && str_contains(
            $logbook,
            "\$function = (string) (\$writerIdentity['funktion'] ?? '');"
        )
        && str_contains($logbook, "'EINSATZTAGEBUCH'")
        // Das TBB haengt an FERNMELDEBETRIEB, der Fachzustaendigkeit des
        // LdF. BEFOERDERUNG traegt der A/W, und der fuehrt kein Buch.
        && str_contains($logbook, "'FERNMELDEBETRIEB'")
        && str_contains(
            $logbook,
            'estab_incident_duty_shift_required($incident)'
        )
        && str_contains($logbook, "duty_shift.`status` = 'AKTIV'")
        && str_contains($logbook, "assignment.`status` = 'ANGENOMMEN'")
        && str_contains(
            $logbook,
            'estab_logbook_designated_writer_assignment('
        )
        && str_contains($logbook, "'shift_id' => null")
        && str_contains($logbook, "'writer_assignment_id' => null")
        && str_contains(
            $logbook,
            "estab_auth_identity_has_function(\$selected, 'ETB', 'Stab')"
        )
        && str_contains(
            $logbook,
            "estab_auth_identity_has_function(\$selected, 'S2', 'Stab')"
        )
        && str_contains($logbook, "'LdF'")
        && str_contains($logbook, "'Fernmelder'"),
    'ETB/TBB authorship does not enforce STRICT writers and exact LOOSE effective functions'
);
$assert(
    str_contains(
        $logbookLifecycle,
        'function estab_logbook_lifecycle_active_shift_id('
    )
        && str_contains(
            $logbookLifecycle,
            'function estab_logbook_lifecycle_permission_mode('
        )
        && str_contains(
            $logbookLifecycle,
            '=== ESTAB_PERMISSION_MODE_LOOSE'
        )
        && str_contains(
            $logbookLifecycle,
            '$permissionMode === ESTAB_PERMISSION_MODE_STRICT'
        )
        && substr_count(
            $logbookLifecycle,
            '$shiftId ??= estab_logbook_lifecycle_active_shift_id('
        ) >= 2
        && substr_count($logbookLifecycle, '?int $shiftId = null') >= 3
        && str_contains($logbookLifecycle, '`estab_shift_id`')
        && str_contains(
            $logbookLifecycle,
            'function estab_logbook_lifecycle_with_system_write_context('
        )
        && str_contains(
            $logbookLifecycle,
            'SELECT @estab_logbook_system_write_incident_id AS `incident_id`'
        )
        && str_contains(
            $logbookLifecycle,
            '@estab_logbook_system_write_book AS `book`'
        )
        && substr_count(
            $logbookLifecycle,
            'estab_logbook_lifecycle_with_system_write_context('
        ) === 3
        && substr_count(
            $logbookLifecycle,
            'SET @estab_logbook_system_write_incident_id = NULL'
        ) >= 2
        && str_contains(
            $logbookLifecycle,
            'eine aktive Dienstschicht.'
        ),
    'logbook lifecycle does not enforce a duty shift only in STRICT while '
        . 'retaining shiftless LOOSE operation'
);
foreach (['ETB' => $etb, 'TBB' => $tbb] as $name => $source) {
    $scope = strpos($source, 'estab_read_require_operational_scope (');
    $constructor = strpos($source, 'new ' . strtolower($name) . '_liste');
    $assert(
        is_int($scope)
            && is_int($constructor)
            && $scope < $constructor
            && str_contains($source, 'estab_dv_has_write_capability (')
            && !str_contains($source, 'duty_assignment_id'),
        $name . ' does not enforce fixed account/capability before data access'
    );
}

/* Attachments keep the same incident/account/effective-function boundary. */
$assert(
    str_contains($attachment, 'estab_attachment_require_operational_identity(')
        && substr_count(
            $attachment,
            'estab_attachment_require_operational_identity('
        ) >= 5
        && str_contains($attachment, 'exact acting tuple and active incident')
        && str_contains(
            $attachment,
            'estab_attachment_origin_context_validate('
        )
        && str_contains(
            $attachment,
            'estab_auth_identity_has_function('
        )
        && str_contains(
            $attachment,
            "\$storedIdentity['funktion']"
        )
        && str_contains($attachment, "\$storedIdentity['rolle']")
        && str_contains(
            $attachment,
            'estab_attachment_origin_permission_context('
        )
        && str_contains($attachment, "'permission_revision' =>")
        && str_contains($attachment, "'duty_assignment_id' =>")
        && str_contains($attachment, "'function_source' =>")
        && str_contains(
            $attachment,
            'estab_attachment_origin_authority_identity('
        ),
    'attachment domain is not exact-effective-function and incident scoped'
);

echo "DV operations security: OK ({$assertions} assertions)\n";
