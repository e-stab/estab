<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/permission_mode.php';
require_once dirname(__DIR__, 2) . '/app/workflow.php';
require_once dirname(__DIR__, 2) . '/app/message_repository.php';

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (string $expectedClass, callable $operation): bool {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception instanceof $expectedClass;
    }
    return false;
};
$read = static function (string $relativePath) use ($root): string {
    $contents = file_get_contents($root . '/' . $relativePath);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read ' . $relativePath);
    }
    return $contents;
};
$slice = static function (
    string $source,
    string $start,
    string $end
): string {
    $startOffset = strpos($source, $start);
    if (!is_int($startOffset)) {
        throw new RuntimeException('Missing source boundary: ' . $start);
    }
    $endOffset = strpos($source, $end, $startOffset + strlen($start));
    if (!is_int($endOffset)) {
        throw new RuntimeException('Missing source boundary: ' . $end);
    }
    return substr($source, $startOffset, $endOffset - $startOffset);
};

$permissionContextKey = ESTAB_PERMISSION_CONTEXT_KEY;
unset($GLOBALS[$permissionContextKey]);
$assert(
    estab_permission_mode(null, true) === ESTAB_PERMISSION_MODE_STRICT
        && estab_permission_mode('', true) === ESTAB_PERMISSION_MODE_STRICT,
    'legacy/default incident permission mode is not STRICT'
);
$assert(
    estab_permission_mode('STRICT') === ESTAB_PERMISSION_MODE_STRICT
        && estab_permission_mode('LOOSE') === ESTAB_PERMISSION_MODE_LOOSE
        && estab_permission_mode_label('STRICT') === 'Streng'
        && estab_permission_mode_label('LOOSE') === 'Locker',
    'canonical permission modes or operator labels changed'
);
foreach ([null, '', 'strict', 'loose', 'ADMIN', 1, [], true] as $invalidMode) {
    $assert(
        $throws(
            InvalidArgumentException::class,
            static fn (): string => estab_permission_mode($invalidMode)
        ),
        'non-canonical permission mode was accepted'
    );
}
$assert(
    estab_permission_context() === null
        && estab_permission_role_checks_enforced(),
    'missing permission context did not fail closed to STRICT'
);

$strictIncident = [
    'active_einsatz_id' => 7,
    'estab_permission_mode' => 'STRICT',
    'revision' => 11,
];
estab_permission_context_set_from_incident($strictIncident);
$assert(
    estab_permission_context() === [
        'incident_id' => 7,
        'mode' => 'STRICT',
        'revision' => 11,
    ]
        && estab_permission_role_checks_enforced()
        && estab_permission_context_matches_incident($strictIncident),
    'STRICT context was not bound to the exact incident snapshot'
);
foreach ([
    array_replace($strictIncident, ['active_einsatz_id' => 8]),
    array_replace($strictIncident, ['revision' => 12]),
    array_replace($strictIncident, ['estab_permission_mode' => 'LOOSE']),
] as $changedIncident) {
    $assert(
        !estab_permission_context_matches_incident($changedIncident),
        'incident/mode/revision race was accepted by the write context'
    );
}
$assert(
    $throws(
        InvalidArgumentException::class,
        static function (): void {
            estab_permission_context_set_from_incident([
                'active_einsatz_id' => 7,
                'estab_permission_mode' => 'loose',
                'revision' => 11,
            ]);
        }
    ),
    'browser-like non-canonical mode entered the request context'
);

$ordinaryAccount = [
    'benutzer' => 'Testkonto',
    'kuerzel' => 's20001',
    'funktion' => 'S2',
    'rolle' => 'Stab',
];
$assert(
    !estab_workflow_is_telecommunications($ordinaryAccount)
        && !estab_workflow_is_telecommunications_lead($ordinaryAccount)
        && !estab_workflow_is_viewer($ordinaryAccount),
    'STRICT mode relaxed a fixed workflow role'
);
$assert(
    !estab_workflow_route_allowed(
        $ordinaryAccount,
        'POST',
        ['task' => 'FM-Eingang']
    )
        && !estab_workflow_route_allowed(
            $ordinaryAccount,
            'POST',
            ['stab_korrekturen_x' => '1']
        ),
    'STRICT mode admitted a cross-role write route'
);

$looseIncident = [
    'active_einsatz_id' => 7,
    'estab_permission_mode' => 'LOOSE',
    'revision' => 12,
];
estab_permission_context_set_from_incident($looseIncident);
$assert(
    !estab_permission_role_checks_enforced()
        && estab_workflow_is_telecommunications($ordinaryAccount, true)
        && estab_workflow_is_telecommunications_lead($ordinaryAccount, true)
        && estab_workflow_is_viewer($ordinaryAccount, true)
        && estab_workflow_is_staff_writer($ordinaryAccount, true)
        && !estab_workflow_is_telecommunications($ordinaryAccount)
        && !estab_workflow_is_telecommunications_lead($ordinaryAccount)
        && !estab_workflow_is_viewer($ordinaryAccount),
    'LOOSE mode did not relax only the fixed workflow-role predicates'
);
$assert(
    estab_workflow_route_allowed(
        $ordinaryAccount,
        'POST',
        ['task' => 'FM-Eingang']
    )
        && estab_workflow_route_allowed(
            $ordinaryAccount,
            'POST',
            ['stab_korrekturen_x' => '1']
        )
        && !estab_workflow_route_allowed(
            $ordinaryAccount,
            'POST',
            ['task' => 'not-a-real-operation']
        )
        && !estab_workflow_route_allowed(
            $ordinaryAccount,
            'POST',
            ['task' => 'FM-Eingang', 'invented_action_x' => '1']
        )
        && !estab_workflow_route_allowed(
            $ordinaryAccount,
            'POST',
            ['fm' => 'FM-Adminmeldung', '00_lfd' => '1']
        )
        && !estab_workflow_route_allowed(
            $ordinaryAccount,
            'POST',
            ['fm' => 'SI-Adminmeldung', '00_lfd' => '1']
        ),
    'LOOSE mode widened operation names, reader routes, or over-posting instead of write roles only'
);

$pendingOutgoing = [
    '04_richtung' => 'A',
    '02_zeit' => '2026-08-01 12:00:00',
    '02_zeichen' => 'ldf001',
    '03_datum' => null,
    '03_zeichen' => '',
    '06_befwegausw' => 'Fu',
    '15_quitdatum' => '2026-08-01 11:59:00',
    '15_quitzeichen' => 'si0001',
    '16_empf' => 'S1_rt',
    'x00_status' => 2,
    'x01_abschluss' => 'f',
    'x02_sperre' => 't',
    'x03_sperruser' => 's20001',
];
$assert(
    !estab_message_object_allowed(
        $ordinaryAccount,
        'telecommunications-save',
        $pendingOutgoing
    )
        && estab_message_object_allowed(
            $ordinaryAccount,
            'telecommunications-save',
            $pendingOutgoing,
            true
        ),
    'LOOSE write policy leaked into reads/default calls or did not relax role ownership'
);
$foreignLock = $pendingOutgoing;
$foreignLock['x03_sperruser'] = 'other1';
$assert(
    !estab_message_object_allowed(
        $ordinaryAccount,
        'telecommunications-save',
        $foreignLock,
        true
    ),
    'LOOSE mode bypassed the message lock owner'
);
$terminalOutgoing = $pendingOutgoing;
$terminalOutgoing['x00_status'] = 8;
$terminalOutgoing['x01_abschluss'] = 't';
$assert(
    !estab_message_object_allowed(
        $ordinaryAccount,
        'telecommunications-save',
        $terminalOutgoing,
        true
    ),
    'LOOSE mode bypassed workflow status/immutability'
);
$wrongDirection = $pendingOutgoing;
$wrongDirection['04_richtung'] = 'E';
$assert(
    !estab_message_object_allowed(
        $ordinaryAccount,
        'telecommunications-save',
        $wrongDirection,
        true
    ),
    'LOOSE mode bypassed message direction semantics'
);
$returnedOutgoing = [
    '04_richtung' => 'A',
    '02_zeit' => null,
    '02_zeichen' => '',
    '03_datum' => null,
    '03_zeichen' => '',
    '14_zeichen' => 's10001',
    '14_funktion' => 'S1',
    '15_quitdatum' => '2026-08-01 12:05:00',
    '15_quitzeichen' => 'si0001',
    '16_empf' => 'S1_rt',
    'x00_status' => 10,
    'x01_abschluss' => 'f',
    'x02_sperre' => 'f',
    'x03_sperruser' => '',
];
$assert(
    !estab_message_object_allowed(
        $ordinaryAccount,
        'staff-correction',
        $returnedOutgoing
    )
        && estab_message_object_allowed(
            $ordinaryAccount,
            'staff-correction',
            $returnedOutgoing,
            true
        ),
    'LOOSE mode did not permit explicit write takeover of a returned outgoing message'
);
$returnedWrongState = $returnedOutgoing;
$returnedWrongState['x00_status'] = 4;
$assert(
    !estab_message_object_allowed(
        $ordinaryAccount,
        'staff-correction',
        $returnedWrongState,
        true
    ),
    'LOOSE returned-message takeover bypassed workflow state'
);

$permissionSource = $read('app/permission_mode.php');
$incidentSource = $read('app/incident.php');
$dvSource = $read('app/dv_operations.php');
$readAuthorizationSource = $read('app/read_authorization.php');
$adminSource = $read('4fadm/incidents.php');
$controllerSource = $read('4fach/mainindex.php');
$listSource = $read('4fach/liste.php');
$migration = $read('docker/db/migrations/115-incident-permission-mode.sql');
$verify = $read('docker/db/verify.sql');
$readiness = $read('app/readiness.php');

$attachmentWriteScope = $slice(
    $readAuthorizationSource,
    'function estab_read_attachment_write_scope(',
    '/** Build an exact filename-to-message map'
);
$ordinaryReadAuthorizationSource = str_replace(
    $attachmentWriteScope,
    '',
    $readAuthorizationSource
);

$writeCapability = $slice(
    $dvSource,
    'function estab_dv_require_write_capability(',
    'function estab_dv_has_write_capability('
);
$strictReadCapability = $slice(
    $dvSource,
    'function estab_dv_require_account_capability(',
    'function estab_dv_has_account_capability('
);
$accountBoundary = $slice(
    $dvSource,
    'function estab_dv_require_operational_account(',
    'function estab_dv_require_active_capability_for_operational_write('
);
$assert(
    str_contains($writeCapability, 'estab_dv_require_operational_account(')
        && str_contains($writeCapability, 'estab_incident_status($connection)')
        && str_contains($writeCapability, "['active_einsatz_id']")
        && str_contains($writeCapability, "['estab_status']")
        && str_contains(
            $writeCapability,
            'estab_incident_role_permissions_enforced($incident)'
        )
        && str_contains($writeCapability, '`nv_funktionsfaehigkeiten`'),
    'LOOSE write boundary does not preserve account/active-incident checks before role relaxation'
);
$assert(
    str_contains($accountBoundary, 'BINARY account.`benutzer` = BINARY ?')
        && str_contains($accountBoundary, 'BINARY account.`kuerzel` = BINARY ?')
        && str_contains($accountBoundary, 'BINARY account.`funktion` = BINARY ?')
        && str_contains($accountBoundary, 'BINARY account.`rolle` = BINARY ?')
        && str_contains($accountBoundary, 'account.`aktiv` = 1')
        && str_contains($accountBoundary, 'account.`estab_gesperrt` = 0')
        && str_contains($accountBoundary, 'estab_auth_shift_access_allowed(')
        && str_contains($accountBoundary, 'LIMIT 1 FOR UPDATE'),
    'LOOSE write boundary can bypass authentication, account blocking, or optional access groups'
);
$assert(
    str_contains($strictReadCapability, '`nv_funktionsfaehigkeiten`')
        && !str_contains(
            $strictReadCapability,
            'estab_incident_role_permissions_enforced'
        )
        && !str_contains(
            $ordinaryReadAuthorizationSource,
            'permission_mode'
        )
        && !str_contains(
            $ordinaryReadAuthorizationSource,
            'role_checks_enforced'
        ),
    'LOOSE write mode broadened read authorization'
);
$assert(
    str_contains(
        $attachmentWriteScope,
        'estab_permission_role_checks_enforced()'
    )
        && str_contains(
            $attachmentWriteScope,
            'estab_message_operation_relaxes_write_role($operation)'
        )
        && str_contains(
            $attachmentWriteScope,
            'estab_message_object_allowed($identity, $operation, $message, true)'
        )
        && str_contains(
            $attachmentWriteScope,
            "\$message['einsatz_id']"
        )
        && str_contains(
            $attachmentWriteScope,
            "\$message['00_lfd']"
        )
        && str_contains(
            $attachmentWriteScope,
            '$scopeIncidentId !== $incidentId'
        )
        && str_contains(
            $attachmentWriteScope,
            "(int) (\$message['00_lfd'] ?? 0) === \$scopeMessageId"
        )
        && str_contains(
            $attachmentWriteScope,
            'hash_equals($shape[$field], $scope[$field])'
        )
        && substr_count(
            $attachmentWriteScope,
            'estab_message_object_allowed('
        ) === 2,
    'LOOSE attachment write scope is not bound to the exact incident, message, operation, and identity'
);
$assert(
    str_contains($listSource, '$expectedFunction')
        && str_contains($listSource, '$expectedRole')
        && str_contains(
            $listSource,
            '$visibility = estab_read_message_visibility_sql ($identity, "m")'
        )
        && str_contains(
            $listSource,
            '$verified = estab_read_filter_messages ($result, $identity)'
        )
        && !str_contains($listSource, '$visibilityIdentity'),
    'LOOSE mode broadened second-sighting list visibility'
);
$correctionQueue = $slice(
    $listSource,
    'case "KORREKTUR":',
    'case "Stab_sichten":'
);
$assert(
    str_contains(
        $correctionQueue,
        'estab_read_require_operational_scope ('
    )
        && str_contains(
            $correctionQueue,
            'estab_permission_context_set_from_incident ($scope ["incident"])'
        )
        && str_contains(
            $correctionQueue,
            'estab_permission_role_checks_enforced ()'
        )
        && str_contains($correctionQueue, 'm.`einsatz_id` = ?')
        && str_contains($correctionQueue, 'm.`x00_status` = 10')
        && str_contains($correctionQueue, "m.`04_richtung` = 'A'")
        && str_contains($correctionQueue, 'm.`02_zeit` IS NULL')
        && str_contains($correctionQueue, 'm.`03_datum` IS NULL')
        && str_contains($correctionQueue, 'm.`15_quitdatum` IS NOT NULL')
        && str_contains(
            $correctionQueue,
            'estab_message_object_allowed ('
        )
        && str_contains($correctionQueue, '"staff-correction"')
        && str_contains($correctionQueue, 'true')
        && !str_contains($correctionQueue, '"staff-read"'),
    'status-10 takeover is not an incident-scoped LOOSE-only write queue'
);
$assert(
    str_contains(
        $incidentSource,
        'estab_permission_context_matches_incident($incident)'
    )
        && str_contains(
            $incidentSource,
            'estab_incident_require_active($connection, true)'
        )
        && str_contains($incidentSource, 'EstabIncidentConflictException'),
    'active-write transaction does not reject incident or permission-mode races'
);
$resubmitBoundary = $slice(
    $read('app/message_repository.php'),
    'function estab_message_resubmit_returned_outgoing(',
    'function estab_message_fetch_for_incident_by_id('
);
$assert(
    str_contains(
        $resubmitBoundary,
        'estab_incident_role_permissions_enforced($incident)'
    )
        && str_contains($resubmitBoundary, '$rolePermissionsEnforced')
        && str_contains($resubmitBoundary, '$authorPredicate')
        && str_contains(
            $resubmitBoundary,
            "\$authorPredicate = ' AND `14_funktion` = ?';"
        )
        && str_contains(
            $resubmitBoundary,
            "['14_zeichen']"
        )
        && str_contains(
            $resubmitBoundary,
            "['14_funktion']"
        )
        && str_contains($resubmitBoundary, "`x00_status` = 10")
        && str_contains($resubmitBoundary, "`04_richtung` = 'A'")
        && str_contains($resubmitBoundary, ' FOR UPDATE')
        && str_contains(
            $resubmitBoundary,
            'estab_message_require_attachment_scope('
        ),
    'returned-message takeover does not retain strict author, actor, state, lock, or attachment boundaries'
);
foreach ([
    'original_author_code',
    'original_author_function',
    'responsible_author_code',
    'responsible_author_function',
] as $responsibilityEvidence) {
    $assert(
        str_contains($resubmitBoundary, $responsibilityEvidence),
        'returned-message takeover omits responsibility evidence: '
            . $responsibilityEvidence
    );
}
$contextMatch = [];
$routeMatch = [];
$assert(
    preg_match('/estab_incident_active\s*\(/', $controllerSource) === 1
        && preg_match(
            '/estab_permission_context_set_from_incident\s*\(/',
            $controllerSource,
            $contextMatch,
            PREG_OFFSET_CAPTURE
        ) === 1
        && preg_match(
            '/estab_workflow_route_allowed\s*\(/',
            $controllerSource,
            $routeMatch,
            PREG_OFFSET_CAPTURE
        ) === 1
        && (int) $contextMatch[0][1] < (int) $routeMatch[0][1],
    'workflow role decision is not based on a database incident snapshot'
);
$csrfGateStart = strpos($controllerSource, '$workflowIdentity !== null');
$csrfGateEnd = strpos(
    $controllerSource,
    'foreach (array ("reset_record", "00_lfd", "msglfd")',
    is_int($csrfGateStart) ? $csrfGateStart : 0
);
$csrfGate = (
    is_int($csrfGateStart)
    && is_int($csrfGateEnd)
    && $csrfGateEnd > $csrfGateStart
) ? substr(
    $controllerSource,
    $csrfGateStart,
    $csrfGateEnd - $csrfGateStart
) : '';
$correctionController = $slice(
    $controllerSource,
    'if (estab_workflow_should_render_primary_view (',
    '/**********************************************************************\\'
);
$assert(
    $csrfGate !== ''
        && str_contains(
            $csrfGate,
            'isset ($returnValue ["stab_korrekturen_x"])'
        )
        && str_contains(
            $csrfGate,
            'estab_csrf_require_post ($_SERVER, $_POST);'
        )
        && str_contains(
            $correctionController,
            '"staff-corrections"'
        )
        && str_contains(
            $correctionController,
            '$formdata ["14_zeichen"] = (string) $workflowSelectedIdentity ["kuerzel"];'
        )
        && str_contains(
            $correctionController,
            '$formdata ["14_funktion"] = (string) $workflowSelectedIdentity ["funktion"];'
        )
        && str_contains(
            $correctionController,
            '$formdata ["13_abseinheit"] = $activeCommandPostName;'
        ),
    'correction queue lacks CSRF protection or keeps the previous author in takeover form fields'
);

$modeUpdate = $slice(
    $incidentSource,
    'function estab_incident_update_permission_mode(',
    'function estab_incident_activate_locked('
);
$assert(
    str_contains($modeUpdate, 'estab_incident_status($connection, true)')
        && str_contains($modeUpdate, '$expectedRevision')
        && str_contains($modeUpdate, '$expectedMode')
        && str_contains($modeUpdate, '$confirmedLoose')
        && str_contains($modeUpdate, 'ESTAB_PERMISSION_MODE_LOOSE')
        && str_contains($modeUpdate, 'SET @estab_permission_mode_admin_write_id')
        && str_contains($modeUpdate, "'berechtigung_geaendert'")
        && str_contains($modeUpdate, '$connection->commit()')
        && str_contains($modeUpdate, '$connection->rollback()'),
    'administrative mode switch lacks revision, confirmation, trigger marker, audit, or transaction protection'
);
$assert(
    str_contains($adminSource, 'estab_csrf_require_post($_SERVER, $_POST)')
        && str_contains($adminSource, 'value="update_permission_mode"')
        && str_contains($adminSource, 'name="expected_permission_mode"')
        && str_contains($adminSource, 'name="status_revision"')
        && str_contains($adminSource, 'name="confirm_loose_permissions"')
        && str_contains($adminSource, 'value="LOOSE"'),
    'incident administration lacks CSRF, stale-form data, or explicit LOOSE confirmation'
);

foreach ([
    "ENUM('STRICT','LOOSE')",
    "NOT NULL DEFAULT 'STRICT'",
    'estab:migration:115:incident-permission-mode:v1',
    'estab_permission_mode_incident_insert',
    'estab_permission_mode_incident_update',
    '@estab_permission_mode_create_write',
    '@estab_permission_mode_admin_write_id',
] as $migrationContract) {
    $assert(
        str_contains($migration, $migrationContract),
        'Migration 115 omits contract: ' . $migrationContract
    );
}
$modeGuard = $slice(
    $migration,
    'CREATE TRIGGER `estab_permission_mode_incident_update`',
    '-- ETB keeps exact account identity'
);
foreach ([
    'einsatz_id',
    'kennung',
    'name',
    'beginn',
    'ende',
    'ort',
    'organisation',
    'fuehrungsstellenname',
    'fuehrungsstellenname_gesperrt',
    'einsatzleitung',
    'beschreibung',
    'metadaten',
    'erstellt_am',
    'erstellt_von',
    'estab_closed_at',
    'estab_closed_by',
    'estab_close_note',
    'estab_retain_until',
    'estab_legal_hold_reason',
    'estab_legal_hold_at',
    'estab_legal_hold_by',
] as $immutableIncidentColumn) {
    $assert(
        preg_match(
            '/NEW\.`' . preg_quote($immutableIncidentColumn, '/')
                . '`\s*<=>\s*OLD\.`'
                . preg_quote($immutableIncidentColumn, '/') . '`/s',
            $modeGuard
        ) === 1,
        'mode guard permits a combined incident-field update: '
            . $immutableIncidentColumn
    );
}
$assert(
    str_contains(
        $modeGuard,
        "BINARY OLD.`estab_status` <> BINARY 'open'"
    )
        && str_contains(
            $modeGuard,
            "BINARY NEW.`estab_status` <> BINARY 'open'"
        )
        && str_contains(
            $modeGuard,
            'NEW.`estab_legal_hold` <> OLD.`estab_legal_hold`'
        ),
    'mode guard permits a combined lifecycle/retention update'
);
foreach ([
    'estab_etb_bi_einsatz',
    'estab_tbb_bi_einsatz',
    'estab_dv94_fernmeldeplan_insert',
    'estab_dv94_fernmeldeplan_immutable',
    'estab_dv94_messenger_insert',
    'estab_dv94_messenger_update',
] as $modeAwareTrigger) {
    $assert(
        substr_count(
            $migration,
            'CREATE TRIGGER `' . $modeAwareTrigger . '`'
        ) === 1,
        'Migration 115 does not canonically replace ' . $modeAwareTrigger
    );
}
$assert(
    substr_count(
        $migration,
        "incident.`estab_permission_mode` = BINARY 'LOOSE'"
    ) >= 6
        && substr_count($migration, 'account.`aktiv` = 1') >= 2
        && substr_count($migration, 'account.`estab_gesperrt` = 0') >= 2
        && str_contains($migration, "incident.`estab_status` = 'open'")
        && str_contains($migration, 'active_incident.`active_einsatz_id`')
        && str_contains($migration, 'Invalid telecommunications plan status transition')
        && str_contains($migration, 'Invalid messenger status transition')
        && str_contains($migration, 'ETB message link targets another incident')
        && str_contains($migration, 'TTB correction target is invalid'),
    'mode-aware database triggers broaden more than fixed write roles'
);
$assert(
    str_contains($verify, "'115-incident-permission-mode.sql'")
        && str_contains($readiness, "'115-incident-permission-mode.sql'")
        && str_contains($verify, "'116-standard-categories.sql'")
        && str_contains($readiness, "'116-standard-categories.sql'")
        && str_contains($verify, "'117-telecom-draft-discard.sql'")
        && str_contains($readiness, "'117-telecom-draft-discard.sql'")
        && str_contains($verify, 'estab_permission_mode')
        && str_contains($readiness, 'estab_permission_mode')
        && str_contains($verify, 'estab_schema_migrations`) = 23')
        && str_contains($readiness, 'estab_schema_migrations) = 23'),
    'Migrations 115-117 and exact ledger are outside verify/readiness gates'
);
$assert(
    str_contains($permissionSource, 'Missing context is fail-closed')
        && str_contains($permissionSource, "!== ESTAB_PERMISSION_MODE_LOOSE")
        && !str_contains($permissionSource, '$_GET')
        && !str_contains($permissionSource, '$_POST')
        && !str_contains($permissionSource, '$_SESSION'),
    'permission mode can be supplied by the browser/session or does not fail closed'
);

unset($GLOBALS[$permissionContextKey]);
echo "permission mode security: OK ({$assertions} assertions)\n";
