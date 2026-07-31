<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin_operations.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$validMatrixInput = ['lagerot' => '11'];
for ($row = 1; $row <= ESTAB_ADMIN_MATRIX_ROWS; $row++) {
    for ($column = 1; $column <= ESTAB_ADMIN_MATRIX_COLUMNS; $column++) {
        $position = (string) $row . (string) $column;
        $validMatrixInput['pos_' . $position] = '';
        $validMatrixInput['rolle_' . $position] = '';
    }
}
$validMatrixInput['pos_11'] = 'S2';
$validMatrixInput['rolle_11'] = 'Stab';
$validMatrixInput['pos_12'] = 'POL';
$validMatrixInput['rolle_12'] = 'FB';

$validMatrix = estab_admin_validate_matrix($validMatrixInput);
$assert($validMatrix['valid'] === true, 'valid 5x4 matrix rejected');
$assert(count($validMatrix['data']['cells']) === 20, 'matrix does not contain exactly 20 cells');
$assert($validMatrix['data']['redcopy'] === '11', 'red-copy position not retained');
$assert($validMatrix['data']['cells']['11']['redcopy'] === true, 'red-copy cell not marked');
$assert($validMatrix['data']['cells']['12']['auto'] === false, 'autosighting was enabled');

$invalid = $validMatrixInput;
$invalid['pos_12'] = 'S2';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'duplicate function accepted');

$invalid = $validMatrixInput;
$invalid['pos_12'] = 'Si';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'reserved Si function accepted');

$invalid = $validMatrixInput;
$invalid['pos_12'] = 'A/W';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'reserved A/W function accepted');

$invalid = $validMatrixInput;
$invalid['pos_12'] = 'ldf';
$assert(
    estab_admin_validate_matrix($invalid)['valid'] === false,
    'reserved LdF function accepted case-insensitively'
);

$invalid = $validMatrixInput;
$invalid['pos_12'] = 'S2;SQL';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'unsafe function identifier accepted');

$invalid = $validMatrixInput;
$invalid['rolle_12'] = 'Administrator';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'unknown recipient role accepted');

$invalid = $validMatrixInput;
$invalid['stasi_12'] = '1';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'autosighting flag accepted');

$invalid = $validMatrixInput;
$invalid['lagerot'] = '13';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'empty red-copy target accepted');

$invalid = $validMatrixInput;
$invalid['rolle_11'] = '';
$assert(
    estab_admin_validate_matrix($invalid)['valid'] === false,
    'roleless red-copy target accepted'
);

$invalid = $validMatrixInput;
$invalid['lagerot'] = '12';
$assert(
    estab_admin_validate_matrix($invalid)['valid'] === false,
    'non-S2 Lage/documentation target accepted'
);

$assert(estab_admin_parse_counter_value('1') === 1, 'minimum counter rejected');
$assert(
    estab_admin_parse_counter_value((string) ESTAB_ADMIN_COUNTER_MAX) === ESTAB_ADMIN_COUNTER_MAX,
    'maximum counter rejected'
);
foreach (['', '0', '-1', '+1', '01', '1.5', '1e3', '1000000000'] as $unsafeCounter) {
    $assert(
        estab_admin_parse_counter_value($unsafeCounter) === null,
        'unsafe counter accepted: ' . $unsafeCounter
    );
}
$assert(
    estab_admin_validate_counter_input(['ea_nummer' => '42'], 'gemeinsam')['valid'] === true,
    'common counter input rejected'
);
$assert(
    estab_admin_validate_counter_input(
        ['e_nummer' => '42', 'a_nummer' => '43'],
        'getrennt'
    )['valid'] === true,
    'split counter input rejected'
);
$assert(
    estab_admin_validate_counter_input(['e_nummer' => '42'], 'getrennt')['valid'] === false,
    'incomplete split counter accepted'
);
$assert(
    estab_admin_html('<script>alert("admin")</script>')
        === '&lt;script&gt;alert(&quot;admin&quot;)&lt;/script&gt;',
    'administrative HTML boundary does not escape active markup'
);

$root = dirname(__DIR__, 2);
$helper = file_get_contents($root . '/app/admin_operations.php');
$assignmentPolicy = file_get_contents($root . '/app/assignment.php');
$matrixPage = file_get_contents($root . '/4fadm/make_fkt.php');
$counterPage = file_get_contents($root . '/4fadm/set_number_after_crash.php');
$resetPage = file_get_contents($root . '/4fach/resetpic.php');
$databaseConfig = file_get_contents($root . '/4fcfg/dbcfg.inc.php');
$messageTools = file_get_contents($root . '/4fach/tools.php');
$messageDummy = file_get_contents($root . '/4fach/dummy.php');
$apache = file_get_contents($root . '/docker/apache/estab.conf');
$adminHttp = file_get_contents($root . '/tests/integration/admin_workflows_http.sh');
$initialSchema = file_get_contents($root . '/docker/db/init/10-schema.sql');
$dvMigration = file_get_contents(
    $root . '/docker/db/migrations/94-dv-organisational-controls.sql'
);
foreach (
    [
        $helper, $assignmentPolicy, $matrixPage, $counterPage, $resetPage, $databaseConfig,
        $messageTools, $messageDummy, $apache, $adminHttp,
    ]
    as $source
) {
    if (!is_string($source)) {
        throw new RuntimeException('Could not read administrative source files');
    }
}

$assert(
    str_contains($databaseConfig, '$conf_4f_db   ["datenbank"]')
        && str_contains($databaseConfig, 'estab_env_identifier("ESTAB_DB_NAME", "estab")'),
    'shared database configuration is incomplete for standalone admin endpoints'
);

$assert(
    substr_count($helper, '$connection->prepare(') >= 10
        && substr_count($helper, '->bind_param(') >= 9
        && str_contains($helper, 'estab_auth_table($table)'),
    'administrative database values are not consistently parameterized'
);
$assert(
    substr_count($helper, '$connection->begin_transaction()') >= 4
        && substr_count($helper, '$connection->rollback()') >= 3
        && str_contains($helper, "'DELETE FROM ' . estab_auth_table(\$matrixTable)")
        && !str_contains($helper, 'TRUNCATE'),
    'transactional matrix/reset/counter boundaries are incomplete'
);
$assert(
    str_contains($helper, 'SELECT GET_LOCK(?, 10)')
        && str_contains($helper, 'estab_message_counter_lock_name')
        && str_contains($helper, ' FOR UPDATE')
        && str_contains($helper, 'target <= $maximum'),
    'counter update lacks serialization or monotonicity enforcement'
);
$assert(
    str_contains($helper, "'Nachrichtennummer Sync'")
        && str_contains($helper, "'Empfängermatrix'")
        && str_contains($helper, "'Grafikstatus Reset'"),
    'administrative audit records are incomplete'
);
$assert(
    substr_count($helper, 'estab_incident_require_active($connection, true)')
        >= 2
        && substr_count(
            $helper,
            'estab_incident_lock_command_post_for_write($connection, $incident);'
        ) >= 2
        && substr_count($helper, 'WHERE `einsatz_id` = ?') >= 2
        && str_contains(
            $helper,
            "'message_counter_repaired'"
        )
        && str_contains(
            $helper,
            'estab_dv_event_append('
        )
        && !str_contains(
            $helper,
            'eStab Systemmeldung.'
        )
        && str_contains(
            $helper,
            'SET `x04_druck` = ?, `x05_druck_d` = NULL'
        ),
    'counter repair is not evidenced without a fake message or print reset '
        . 'is not bound to the fully configured, locked active incident'
);
$assert(
    str_contains(
        $helper,
        '(`einsatz_id`, `p_was`, `p_ereignis`) VALUES (?, ?, ?)'
    )
        && str_contains($counterPage, 'data-estab-requires-incident')
        && str_contains($resetPage, 'data-estab-requires-incident')
        && str_contains($counterPage, 'EstabNoActiveIncidentException')
        && str_contains($resetPage, 'EstabNoActiveIncidentException')
        && str_contains(
            $counterPage,
            'EstabIncidentConfigurationException'
        )
        && str_contains(
            $resetPage,
            'EstabIncidentConfigurationException'
        ),
    'incident-scoped administrative actions lack audit or fail-closed UI gates'
);
$assert(
    str_contains($helper, 'estab_assignment_acquire_policy_lock(')
        && substr_count(
            $helper,
            'estab_assignment_reconcile_accounts('
        ) === 2
        && str_contains($matrixPage, "\$conf_4f_tbl['benutzer']")
        && is_string($assignmentPolicy)
        && str_contains($assignmentPolicy, '`rolle` = ?')
        && str_contains($assignmentPolicy, '`aktiv` = 0')
        && str_contains($assignmentPolicy, "`sid` = ''")
        && !str_contains($assignmentPolicy, 'SET `funktion` =')
        && !str_contains($assignmentPolicy, 'DROP TABLE'),
    'matrix update does not reconcile roles and revoke orphaned assignments safely'
);
$assert(
    str_contains($helper, 'function estab_admin_replace_matrix_and_standard(')
        && substr_count(
            $helper,
            'estab_admin_write_matrix($connection, $matrixTable, $matrix)'
        ) === 2
        && str_contains(
            $helper,
            'estab_admin_write_matrix($connection, $standardMatrixTable, $matrix)'
        )
        && str_contains($helper, '`mtx_rc2`, `mtx_auto`')
        && str_contains(
            $helper,
            "estab_assignment_matrix_audit(\n                'replace_active_and_standard'"
        )
        && str_contains($helper, '$connection->commit()'),
    'active and standard matrices are not replaced together with both flags'
);
$assert(
    str_contains(
        $matrixPage,
        "\$standardMatrixTable = \$conf_4f_tbl['empfmtx'] . '_standard'"
    )
        && str_contains($matrixPage, "\$action === 'load_standard'")
        && str_contains(
            $matrixPage,
            'estab_admin_fetch_matrix($connection, $standardMatrixTable)'
        )
        && str_contains($matrixPage, 'estab_admin_replace_matrix_and_standard(')
        && str_contains($matrixPage, 'value="save_matrix"')
        && str_contains($matrixPage, 'value="save_matrix_and_standard"')
        && str_contains($matrixPage, 'value="load_standard"')
        && str_contains(
            $matrixPage,
            'data-estab-confirm="replace-editor-with-standard"'
        )
        && str_contains($matrixPage, 'data-estab-confirm="replace-standard"')
        && str_contains($matrixPage, 'bisherigen Standard ersetzen')
        && str_contains($matrixPage, '<code>LdF</code> sind reserviert'),
    'matrix editor does not expose the three explicit database-backed preset actions'
);
$assert(
    !str_contains($matrixPage, 'fopen(')
        && !str_contains($matrixPage, 'file_put_contents(')
        && !str_contains($matrixPage, 'deault.fkt')
        && !str_contains($matrixPage, 'default.fkt'),
    'matrix editor still writes a generated PHP configuration file'
);
$assert(
    !str_contains($messageTools, 'sichter_online')
        && !str_contains($messageDummy, 'sichter_online')
        && !str_contains($messageTools, '`mtx_auto`')
        && !str_contains($messageDummy, '`mtx_auto`'),
    'runtime still contains an automatic-sighting bypass'
);
$assert(
    str_contains($helper, "\$type = \$function !== '' && \$role !== '' ? 'cb' : 't';")
        && str_contains($initialSchema, "`mtx_typ` SET('cb','t')")
        && str_contains($dvMigration, "`mtx_typ` SET('cb','t')"),
    'supported recipient matrices are not restricted to canonical cb/t cells'
);
$assert(
    str_contains($adminHttp, 'refusing mutation outside an estab_ci project')
        && str_contains($adminHttp, 'absenden_x laden_x speichern_x')
        && str_contains($adminHttp, 'BEFORE INSERT ON \`nv_empfmtx_standard\`')
        && str_contains($adminHttp, 'assert_status 500')
        && str_contains($adminHttp, 'exact_matrix_snapshot')
        && str_contains($adminHttp, 'exact_standard_matrix_snapshot')
        && str_contains(
            $adminHttp,
            'failed combined save did not roll back the active matrix exactly'
        ),
    'admin HTTP proof omits legacy GET inertness or the two-table rollback gate'
);

foreach ([$matrixPage, $counterPage, $resetPage] as $page) {
    $assert(
        str_contains($page, "(\$_SERVER['REQUEST_METHOD'] ?? '') === 'POST'")
            && str_contains($page, 'estab_csrf_require_post($_SERVER, $_POST)')
            && str_contains($page, 'estab_admin_require_http_auth($_SERVER)')
            && str_contains($page, ', true, 303)'),
        'admin page lacks POST, Session-CSRF, Basic-Auth boundary or PRG'
    );
    $assert(
        !str_contains($page, 'mysql_query(')
            && !str_contains($page, 'mysql_escape_string('),
        'admin page retains a raw legacy SQL call'
    );
}

$assert(
    str_contains($apache, '4fach/resetpic\\.php$')
        && str_contains(
            $apache,
            '^/4fach/(?:all_msg|counter|status)\\.php$'
        )
        && str_contains($apache, '^/4fach/upload/(?!upload\\.php$)')
        && str_contains($apache, '^/4fbak(?:/|$)')
        && str_contains($apache, '^/4fach/Print(?:/|$)')
        && str_contains($apache, 'Require all denied'),
    'Apache does not protect resetpic or block unsafe legacy endpoints'
);

printf("Admin operations security tests: OK (%d assertions)\n", $assertions);
