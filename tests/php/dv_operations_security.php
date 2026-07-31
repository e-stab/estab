<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/operational_guard.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
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
    'administrative close is not an explicit control exception'
);
foreach (
    [
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/4fach/mainindex.php/4fadm',
            'SCRIPT_NAME' => '/4fach/mainindex.php',
            'PHP_SELF' => '/4fach/mainindex.php/4fadm',
            'PATH_INFO' => '/4fadm',
        ],
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/4fadm/incidents.php',
            'SCRIPT_NAME' => '/4fach/mainindex.php',
            'PHP_SELF' => '/4fach/mainindex.php',
        ],
        [
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/4fach/mainindex.php/4fadm',
            'PHP_SELF' => '/4fach/mainindex.php/4fadm',
        ],
        [
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/4fach/mainindex.php',
            'PHP_SELF' => '/4fach/mainindex.php/',
            'PATH_INFO' => '/',
        ],
    ] as $pathInfoBypass
) {
    $assert(
        estab_operational_control_exception(
            $pathInfoBypass,
            ['reset_record' => '1']
        ) === null,
        'attacker-controlled path was accepted as an administration boundary'
    );
}
$assert(
    estab_operational_request_path(
        [
            'REQUEST_URI' => '/4fadm/incidents.php',
            'SCRIPT_NAME' => '/4fach/mainindex.php',
        ]
    ) === '/4fach/mainindex.php',
    'request URI replaced the identity of the script Apache executed'
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
        'messenger lifecycle transition is blocked: ' . $transition
    );
}
$assert(
    estab_operational_control_exception(
        [
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/4fach/fuehrungsstelle.php',
        ],
        [
            'operation_action' => 'messenger_transition',
            'transition' => 'report',
        ]
    ) === null,
    'supervisor report is broadly exempt from the away-account guard'
);
$assert(
    estab_operational_control_exception(
        [
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/4fach/fuehrungsstelle.php',
        ],
        ['operation_action' => 'create_plan']
    ) === null,
    'S6 write bypasses the normal active-hat guard'
);
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

$dv = $read('app/dv_operations.php');
$attachment = $read('app/attachment.php');
$databaseConfig = $read('4fcfg/dbcfg.inc.php');
$operationalGuard = $read('app/operational_guard.php');
$csrf = $read('app/csrf.php');
$logbook = $read('app/logbook.php');
$auth = $read('app/auth.php');
$migration = $read(
    'docker/db/migrations/94-dv-organisational-controls.sql'
);
$etbDutyMigration = $read(
    'docker/db/migrations/96-etb-duty-function.sql'
);
$dynamicSchema = $read('app/dynamic_schema.php');
$dvIntegration = $read('tests/integration/dv_operations.php');
$operationsUi = $read('4fach/fuehrungsstelle.php');
$adminUi = $read('4fadm/fuehrungsstelle.php');
$assert(
    str_contains(
        $operationalGuard,
        'estab_incident_command_post_name($incident);'
    )
        && str_contains(
            $operationalGuard,
            'catch (EstabIncidentConfigurationException)'
        )
        && str_contains(
            $operationalGuard,
            'noch kein Name der Führungsstelle festgelegt'
        ),
    'request-wide write guard accepts an incomplete active incident'
);
$assert(
    str_contains($operationsUi, 'EstabIncidentConfigurationException')
        && str_contains($adminUi, 'EstabIncidentConfigurationException'),
    'duty-station controllers turn a missing command-post name into HTTP 500'
);
$httpSmoke = $read('tests/integration/http_smoke.sh');

$assert(
    str_contains(
        $csrf,
        'final class EstabCsrfException extends RuntimeException'
    )
        && str_contains(
            $csrf,
            'throw new EstabCsrfException('
        )
        && str_contains(
            $operationsUi,
            'estab_csrf_require_post($_SERVER, $_POST);'
        )
        && str_contains(
            $operationsUi,
            '} catch (EstabCsrfException) {'
                . "\n        http_response_code(403);"
        )
        && str_contains(
            $adminUi,
            'estab_csrf_require_post($_SERVER, $_POST);'
        )
        && str_contains(
            $adminUi,
            '} catch (EstabCsrfException) {'
                . "\n        http_response_code(403);"
        ),
    'Führungsstellen-CSRF failure is not specifically mapped to HTTP 403'
);
$assert(
    str_contains(
        $databaseConfig,
        "require_once __DIR__ . '/../app/operational_guard.php'"
    )
        && str_contains(
            $databaseConfig,
            'estab_operational_write_enforce('
        ),
    'common database boundary does not enforce the request-wide write guard'
);
$assert(
    str_contains(
        $operationalGuard,
        'Operative Eingaben sind gesperrt, weil kein Einsatz aktiv'
    )
        && !str_contains(
            $operationalGuard,
            "if (\$incident === null) {\n            return;"
        ),
    'request-wide guard is open when no incident is active'
);
$assert(
    str_contains(
        $dv,
        'estab_dv_require_messenger_available_for_operational_write('
    )
        && str_contains(
            $dv,
            "AND `status` IN ('UEBERNOMMEN','UEBERGEBEN','RUECKWEG')"
        )
        && !str_contains(
            $dv,
            'if (!$hasShiftHistory)'
        ),
    'messenger absence guard is not fail-closed'
);
$assert(
    str_contains($dv, "\$session['fm_zweite_sichtung'] = 0;")
        && str_contains($dv, "\$session['si_zweite_sichtung'] = 0;"),
    'hat selection retains privileged second-sighting session modes'
);
$assert(
    str_contains(
        $operationalGuard,
        '$identity = estab_auth_session_identity($session);'
    )
        && str_contains(
            $operationalGuard,
            "estab_operational_write_abort(\n            423,"
        )
        && str_contains(
            $dv,
            'WHERE assignment.`dienstbesetzung_id` = ?'
        )
        && str_contains(
            $dv,
            'AND active_incident.`active_einsatz_id`'
        )
        && str_contains(
            $dv,
            "AND duty_shift.`status` = 'AKTIV'"
        )
        && str_contains(
            $dv,
            "AND assignment.`status` = 'ANGENOMMEN'"
        )
        && str_contains(
            $dv,
            "AND incident.`estab_status` = 'open'"
        )
        && str_contains(
            $dv,
            'AND BINARY account.`benutzer` = BINARY ?'
        )
        && str_contains(
            $dv,
            'AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
        )
        && str_contains(
            $dv,
            'AND BINARY assignment.`funktion` = BINARY ?'
        )
        && str_contains(
            $dv,
            'AND BINARY assignment.`rolle` = BINARY ?'
        )
        && str_contains($dv, 'AND account.`aktiv` = 1')
        && str_contains($dv, 'AND account.`estab_gesperrt` = 0')
        && str_contains($dv, 'LIMIT 1 FOR UPDATE'),
    'operational write guard is not bound to the exact selected active hat'
);
$assert(
    str_contains(
        $dv,
        "const ESTAB_DV_REQUIRED_HATS = ['S2', 'Si', 'S6', 'LdF', 'A/W'];"
    )
        && str_contains($adminUi, 'S2, Si, S6, LdF und A/W'),
    'S6 is not a required, operator-visible duty assignment'
);
$acceptPrepare = strpos($dv, '$prepared = estab_dv_prepare_assignment_schema(');
$acceptTransaction = strpos(
    $dv,
    'return estab_incident_with_active_write(',
    $acceptPrepare === false ? 0 : $acceptPrepare
);
$selectPrepare = strpos(
    $dv,
    '$prepared = estab_dv_prepare_assignment_schema(',
    $acceptPrepare === false ? 0 : $acceptPrepare + 1
);
$selectTransaction = strpos(
    $dv,
    'if (!$connection->begin_transaction())',
    $selectPrepare === false ? 0 : $selectPrepare
);
$assert(
    str_contains($dv, "require_once __DIR__ . '/dynamic_schema.php';")
        && substr_count(
            $dv,
            'estab_dv_prepare_assignment_schema('
        ) >= 3
        && is_int($acceptPrepare)
        && is_int($acceptTransaction)
        && $acceptPrepare < $acceptTransaction
        && is_int($selectPrepare)
        && is_int($selectTransaction)
        && $selectPrepare < $selectTransaction
        && str_contains(
            $dynamicSchema,
            'estab_dynamic_schema_require_no_transaction($connection);'
        )
        && str_contains(
            $dvIntegration,
            'rejected dynamic DDL implicitly ended the caller transaction'
        ),
    'hat table reconciliation can run inside or commit a duty transaction'
);
$assert(
    str_contains($etbDutyMigration, "'EINSATZTAGEBUCH'")
        && str_contains(
            $etbDutyMigration,
            "('S2',  'Stab', 'EINSATZTAGEBUCH'"
        )
        && str_contains(
            $etbDutyMigration,
            "('ETB', 'Stab', 'EINSATZTAGEBUCH'"
        )
        && str_contains(
            $etbDutyMigration,
            'capability primary key is incompatible'
        )
        && str_contains(
            $dvIntegration,
            'one account did not switch from its S2 to its accepted S3 hat'
        )
        && str_contains(
            $dvIntegration,
            'one account did not switch from its Si to its accepted ETB hat'
        )
        && str_contains(
            $dvIntegration,
            'ETB-only hat received unrelated message/category tables'
        ),
    'S2/S3 and ETB/Si combined duty functions lack least-privilege evidence'
);
$assert(
    str_contains($dv, 'function estab_dv_messenger_candidates(')
        && str_contains(
            $dv,
            "BINARY b.`funktion` = BINARY 'A/W'"
        )
        && str_contains(
            $operationsUi,
            '$users = estab_dv_messenger_candidates('
        ),
    'messenger selection is not restricted to accepted active A/W hats'
);
$assert(
    str_contains(
        $migration,
        '`offener_nachrichtenauftrag` BIGINT'
    )
        && str_contains(
            $migration,
            'UNIQUE KEY `uq_melderauftrag_offene_nachricht`'
        )
        && !str_contains(
            $migration,
            'UNIQUE KEY `uq_melderauftrag_nachricht`'
        )
        && str_contains(
            $dv,
            'estab_dv_require_no_open_messenger_for_redispatch('
        ),
    'cancel/reassignment and completed-message uniqueness are not modelled'
);
$assert(
    str_contains($dv, 'function estab_dv_require_selected_capability(')
        && str_contains($dv, "'FERNMELDEBETRIEB'")
        && str_contains(
            $dv,
            "['cancel', 'report']"
        )
        && str_contains(
            $dv,
            "['accept', 'deliver', 'return_path', 'returned']"
        ),
    'selected LdF and personal messenger responsibilities are not separated'
);
$assert(
    str_contains(
        $migration,
        'BINARY NEW.`ziel` <> BINARY OLD.`ziel`'
    )
        && str_contains(
            $migration,
            'Invalid messenger delivery evidence'
        )
        && str_contains(
            $migration,
            'Invalid messenger return-path evidence'
        )
        && str_contains(
            $migration,
            'Invalid messenger report evidence'
        ),
    'messenger transition trigger does not preserve prior chain-of-custody evidence'
);
$assert(
    str_contains(
        $migration,
        "OLD.`status` = 'ENTWURF' AND NEW.`status` = 'ENTWURF'"
    )
        && str_contains(
            $migration,
            "OLD.`status` = 'ENTWURF' AND NEW.`status` = 'AKTIV'"
        )
        && str_contains(
            $migration,
            "OLD.`status` = 'AKTIV' AND NEW.`status` = 'ERSETZT'"
        )
        && str_contains(
            $migration,
            'NEW.`version` <> OLD.`version`'
        )
        && str_contains(
            $migration,
            'release_account.`estab_gesperrt` = 0'
        )
        && str_contains(
            $migration,
            'NEW.`fernmeldeplan_eintrag_id` <>'
        ),
    'telecommunications plan or route identity is mutable outside exact transitions'
);
$assert(
    str_contains($dv, 'function estab_dv_initiate_handover_shift(')
        && str_contains($dv, 'function estab_dv_confirm_handover_shift(')
        && str_contains($dv, 'function estab_dv_cancel_handover_request(')
        && str_contains(
            $dv,
            'function estab_dv_user_handover_requests('
        )
        && str_contains(
            $dv,
            'function estab_dv_active_shift_summary('
        )
        && str_contains(
            $dv,
            'AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
        )
        && str_contains($migration, "'INITIIERT','BESTAETIGT','STORNIERT'")
        && str_contains($migration, '`stornierungsgrund` TEXT NULL'),
    'two-stage personally confirmed and cancellable handover is incomplete'
);
$assert(
    str_contains(
        $operationsUi,
        'function dv_operations_redirect_after_hat('
    )
        && str_contains(
            $operationsUi,
            "\$_SESSION['estab_pending_navigation_key']"
        )
        && str_contains(
            $operationsUi,
            'estab_navigation_duty_access_allowed('
        )
        && str_contains(
            $operationsUi,
            'data-estab-duty-selection-required'
        ),
    'post-login duty selection does not safely continue the requested area'
);
$assert(
    substr_count(
        $operationsUi,
        'class="estab-tool-table-wrap estab-tool-table-responsive"'
    ) === 2
        && substr_count(
            $operationsUi,
            '<caption class="estab-visually-hidden">'
        ) === 2,
    'command-post duty and telecommunications tables are not mobile-labelled '
        . 'responsive cards'
);
foreach (
    [
        'Schicht',
        'Funktion',
        'Status',
        'Aktion',
        'Betriebsstelle',
        'Rufname',
        'Weg',
        'Verkehrsform',
        'Vermerke',
    ] as $mobileLabel
) {
    $assert(
        str_contains(
            $operationsUi,
            'data-label="' . $mobileLabel . '"'
        ),
        'command-post mobile table cell lacks label: ' . $mobileLabel
    );
}
$assert(
    str_contains(
        $dv,
        '$oldHat[\'successor_assignment_id\'] = $successorId'
    )
        && str_contains(
            $migration,
            'BINARY successor_assignment.`funktion` ='
        )
        && str_contains(
            $migration,
            'BINARY successor_assignment.`rolle` = BINARY OLD.`rolle`'
        )
        && !str_contains(
            $migration,
            'BINARY successor_assignment.`benutzer_kuerzel` ='
                . "\n                  BINARY OLD.`benutzer_kuerzel`"
        ),
    'personnel handover successor is still tied to the outgoing user account'
);
$assert(
    str_contains(
        $adminUi,
        'Ein ungesperrtes Konto kann bereits für die kommende Schicht'
    )
        && str_contains($adminUi, "'nicht angemeldet'")
        && str_contains($adminUi, '$userBlocked ? \'disabled\' : \'\'')
        && str_contains($auth, '`estab_gesperrt`')
        && !str_contains(
            $migration,
            "BINARY NEW.`benutzer_kuerzel`\n"
                . "          AND assigned_account.`aktiv` = 1"
        ),
    'offline planning or blocked-account labelling is not modelled in the admin UI'
);
$assert(
    str_contains($dv, 'estab_incident_close_preflight(')
        && str_contains(
            $dv,
            'Die letzte aktive Schicht kann erst nach Abschluss'
        )
        && str_contains(
            $adminUi,
            'bis alle Nachrichten, Sperren, Anhänge, Nachweise'
        )
        && str_contains(
            $migration,
            'Active duty shift has unfinished incident work'
        ),
    'final active shift can close before the fachlicher completion preflight'
);
$assert(
    preg_match(
        '/if \\(\\$action === \'close_shift\'\\).*?'
            . 'estab_dv_close_shift\\(.*?'
            . '\\$conf_4f_tbl\\[\'protokoll\'\\],\\s*'
            . '\\(string\\) \\$conf_4f\\[\'ablage_dir\'\\]\\s*\\);/s',
        $adminUi
    ) === 1
        && str_contains(
            $httpSmoke,
            "assert_body 'Anhang-Integritätsfehler: 1'"
        )
        && str_contains(
            $httpSmoke,
            'rejected final-shift close changed shift or hats'
        )
        && str_contains(
            $httpSmoke,
            'attachment_fixture_bytes tamper "$tampered_attachment"'
        ),
    'HTTP final-shift close omits live attachment bytes or rollback evidence'
);
$assert(
    str_contains($migration, '`ruecknachricht_vorhanden` TINYINT(1)')
        && str_contains($operationsUi, 'name="ruecknachricht_vorhanden"')
        && str_contains($dv, "'ruecknachricht_sha256'")
        && str_contains($dv, 'estab_dv_verify_messenger_snapshots('),
    'messenger return decision or terminal snapshot evidence is incomplete'
);
$assert(
    str_contains(
        $attachment,
        'estab_attachment_require_operational_identity('
    )
        && substr_count(
            $attachment,
            'estab_attachment_require_operational_identity('
        ) >= 5,
    'attachment reserve/claim/finalize/upload domain lacks the duty guard'
);
$assert(
    str_contains(
        $dv,
        'estab_incident_lock_command_post_for_write($connection, $incident);'
    ),
    'session duty selection accepts an incident without a command-post name'
);
$assert(
    str_contains(
        $logbook,
        'estab_dv_require_active_capability_for_operational_write('
    )
        && str_contains($logbook, "'EINSATZTAGEBUCH'")
        && str_contains($logbook, "'BEFOERDERUNG'"),
    'ETB/TBB domain does not enforce canonical Fachzuständigkeit'
);
$assignmentNormalization = strpos(
    $auth,
    "\$dutyAssignmentValue = \$session['estab_duty_assignment_id']"
);
$cacheKey = strpos($auth, "\$cacheKey = hash('sha256'");
$assert(
    is_int($assignmentNormalization)
        && is_int($cacheKey)
        && $assignmentNormalization < $cacheKey
        && str_contains($auth, "'duty_assignment_id' => \$dutyAssignmentId")
        && str_contains(
            $auth,
            "\$authenticatedIdentity['duty_assignment_id'] ="
        )
        && substr_count($auth, 'return $authenticatedIdentity;') === 2,
    'validated duty assignment is not preserved through authentication'
);
$messageController = $read('4fach/data_hndl.php');
$assert(
    str_contains(
        $messageController,
        '"duty_assignment_id" =>'
    )
        && str_contains(
            $messageController,
            '$attachmentReadIdentity ["duty_assignment_id"] ?? null'
        ),
    'message event actor drops the selected duty assignment before commit'
);

echo "DV operations security: OK ({$assertions} assertions)\n";
