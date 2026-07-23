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
$validMatrixInput['pos_11'] = 'S1';
$validMatrixInput['rolle_11'] = 'Stab';
$validMatrixInput['pos_12'] = 'POL';
$validMatrixInput['rolle_12'] = 'FB';
$validMatrixInput['stasi_12'] = '1';

$validMatrix = estab_admin_validate_matrix($validMatrixInput);
$assert($validMatrix['valid'] === true, 'valid 5x4 matrix rejected');
$assert(count($validMatrix['data']['cells']) === 20, 'matrix does not contain exactly 20 cells');
$assert($validMatrix['data']['redcopy'] === '11', 'red-copy position not retained');
$assert($validMatrix['data']['cells']['11']['redcopy'] === true, 'red-copy cell not marked');
$assert($validMatrix['data']['cells']['12']['auto'] === true, 'boolean auto flag not retained');

$invalid = $validMatrixInput;
$invalid['pos_12'] = 'S1';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'duplicate function accepted');

$invalid = $validMatrixInput;
$invalid['pos_12'] = 'Si';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'reserved Si function accepted');

$invalid = $validMatrixInput;
$invalid['pos_12'] = 'A/W';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'reserved A/W function accepted');

$invalid = $validMatrixInput;
$invalid['pos_12'] = 'S2;SQL';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'unsafe function identifier accepted');

$invalid = $validMatrixInput;
$invalid['rolle_12'] = 'Administrator';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'unknown recipient role accepted');

$invalid = $validMatrixInput;
$invalid['stasi_12'] = 'yes';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'non-boolean auto flag accepted');

$invalid = $validMatrixInput;
$invalid['lagerot'] = '13';
$assert(estab_admin_validate_matrix($invalid)['valid'] === false, 'empty red-copy target accepted');

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
$matrixPage = file_get_contents($root . '/4fadm/make_fkt.php');
$counterPage = file_get_contents($root . '/4fadm/set_number_after_crash.php');
$resetPage = file_get_contents($root . '/4fach/resetpic.php');
$databaseConfig = file_get_contents($root . '/4fcfg/dbcfg.inc.php');
$apache = file_get_contents($root . '/docker/apache/estab.conf');
foreach ([$helper, $matrixPage, $counterPage, $resetPage, $databaseConfig, $apache] as $source) {
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
    substr_count($helper, '$connection->prepare(') >= 12
        && substr_count($helper, '->bind_param(') >= 9
        && str_contains($helper, 'estab_auth_table($table)'),
    'administrative database values are not consistently parameterized'
);
$assert(
    substr_count($helper, '$connection->begin_transaction()') >= 3
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
    !str_contains($helper, 'nv_benutzer')
        && !str_contains($matrixPage, "conf_4f_tbl['benutzer']")
        && !str_contains($matrixPage, 'nv_benutzer'),
    'matrix update unexpectedly changes account assignments'
);
$assert(
    !str_contains($matrixPage, 'fopen(')
        && !str_contains($matrixPage, 'deault.fkt')
        && !str_contains($matrixPage, 'default.fkt'),
    'matrix editor still writes a generated PHP configuration file'
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
        && str_contains($apache, '<Location "/4fach/all_msg.php">')
        && str_contains($apache, '^/4fach/upload/(?!upload\\.php$)')
        && str_contains($apache, '^/4fbak(?:/|$)')
        && str_contains($apache, '^/4fach/Print(?:/|$)')
        && str_contains($apache, 'Require all denied'),
    'Apache does not protect resetpic or block unsafe legacy endpoints'
);

printf("Admin operations security tests: OK (%d assertions)\n", $assertions);
