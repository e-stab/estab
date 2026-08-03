<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/category.php';
require_once __DIR__ . '/../../app/workflow.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $operation, string $class, string $message) use ($assert): void {
    try {
        $operation();
    } catch (Throwable $exception) {
        $assert($exception instanceof $class, $message . ': ' . get_class($exception));
        return;
    }
    $assert(false, $message . ': no exception');
};

foreach (ESTAB_CATEGORY_TYPES as $type) {
    $assert(estab_category_validate_type($type) === $type, 'valid category type rejected');
}
foreach (['', 'MASTER', 'admin', 'user`', null, 1] as $type) {
    $throws(
        static fn () => estab_category_validate_type($type),
        EstabCategoryInputException::class,
        'unsafe category type accepted'
    );
}

foreach (['1', '42', PHP_INT_MAX] as $id) {
    $assert(estab_category_positive_id($id) >= 1, 'positive category ID rejected');
}
foreach (['', '0', '-1', '+1', '01', '1.0', '1 OR 1=1', [], null] as $id) {
    $throws(
        static fn () => estab_category_positive_id($id),
        EstabCategoryInputException::class,
        'unsafe category ID accepted'
    );
}
$assert(estab_workflow_category_filter('alle') === 'alle', 'clear-all category filter rejected');
$assert(estab_workflow_category_filter('17') === 17, 'positive category filter rejected');
foreach (['', '0', '01', "x' OR 1", [], null] as $filter) {
    $assert(estab_workflow_category_filter($filter) === null, 'unsafe category filter accepted');
}

$rawPayload = [
    'kategorie' => '<script>',
    'beschreibung' => 'Quotes "\' & <script>alert(1)</script> – äöü',
];
$validatedPayload = estab_category_validate_payload($rawPayload);
$assert($validatedPayload === $rawPayload, 'raw UTF-8 category data was entity-encoded or changed');
$assert(
    estab_auth_html($validatedPayload['beschreibung'])
        === 'Quotes &quot;&#039; &amp; &lt;script&gt;alert(1)&lt;/script&gt; – äöü',
    'category output boundary does not neutralise active markup'
);
foreach ([
    ['kategorie' => '', 'beschreibung' => ''],
    ['kategorie' => '12345678901', 'beschreibung' => ''],
    ['kategorie' => "bad\nname", 'beschreibung' => ''],
    ['kategorie' => 'ok', 'beschreibung' => str_repeat('x', 255)],
    ['kategorie' => 'ok', 'beschreibung' => "bad\0value"],
] as $payload) {
    $throws(
        static fn () => estab_category_validate_payload($payload),
        EstabCategoryInputException::class,
        'invalid category payload accepted'
    );
}

$identity = [
    'benutzer' => 'Kategorie Test',
    'kuerzel' => 'cat001',
    'funktion' => 'S1',
    'rolle' => 'Stab',
];
$tables = [
    'masterkatego' => 'nv_masterkatego',
    'masterkategolk' => 'nv_masterkategolink',
    'usrtblprefix' => 'usr_',
];
$master = estab_category_scope('master', $identity, $tables);
$function = estab_category_scope('fkt', $identity, $tables);
$user = estab_category_scope('user', $identity, $tables);
$assert($master['category_table'] === 'nv_masterkatego', 'master category table changed');
$assert($function['category_table'] === 'usr__fkt_s1_katego', 'function table not session-derived');
$assert($function['link_table'] === 'usr__fkt_s1_kategolink', 'function link table not session-derived');
$assert($user['category_table'] === 'usr_s1_cat001_katego', 'user table not session-derived');
$assert($user['link_table'] === 'usr_s1_cat001_kategolink', 'user link table not session-derived');
$looseIdentity = [
    'benutzer' => 'Kategorie Zusatzfunktion',
    'kuerzel' => 'extra1',
    'funktion' => 'LdF',
    'rolle' => 'Fernmelder',
    'estab_permission_mode' => 'LOOSE',
    'estab_additional_functions' => [
        ['funktion' => 'S6', 'rolle' => 'Stab'],
    ],
];
$looseS6 = estab_category_identity_for_function($looseIdentity, 'S6');
$assert(
    $looseS6['funktion'] === 'S6'
        && $looseS6['rolle'] === 'Stab'
        && $looseS6['authorization_account_function'] === 'LdF',
    'LOOSE additional function was not retained as category actor'
);
$looseFunctionScope = estab_category_scope('fkt', $looseS6, $tables);
$assert(
    $looseFunctionScope['category_table'] === 'usr__fkt_s6_katego'
        && $looseFunctionScope['acting_function'] === 'S6'
        && $looseFunctionScope['acting_role'] === 'Stab',
    'LOOSE additional function was routed into the primary category tables'
);
$throws(
    static fn () => estab_category_identity_for_function($looseIdentity, 'Si'),
    EstabCategoryAuthorizationException::class,
    'unassigned LOOSE category function accepted'
);
$strictIdentity = [
    'benutzer' => 'Kategorie Dienstfunktion',
    'kuerzel' => 'hat001',
    'funktion' => 'S6',
    'rolle' => 'Stab',
    'duty_assignment_id' => 12,
    'estab_permission_mode' => 'STRICT',
    'estab_additional_functions' => [
        // A stale value from an earlier LOOSE request must never survive a
        // mode switch as an operational category selector.
        ['funktion' => 'LdF', 'rolle' => 'Fernmelder'],
    ],
];
$assert(
    estab_category_identity_for_function($strictIdentity, 'S6')['funktion']
        === 'S6',
    'selected STRICT duty function rejected for categories'
);
$throws(
    static fn () => estab_category_identity_for_function($strictIdentity, 'LdF'),
    EstabCategoryAuthorizationException::class,
    'STRICT category scope escaped the selected duty function'
);
$throws(
    static fn () => estab_category_scope(
        'user',
        $identity,
        array_replace($tables, ['usrtblprefix' => 'bad`;'])
    ),
    EstabCategoryAuthorizationException::class,
    'unsafe dynamic table prefix accepted'
);
$awIdentity = $identity;
$awIdentity['funktion'] = 'A/W';
$awIdentity['rolle'] = 'Fernmelder';
$throws(
    static fn () => estab_category_scope('fkt', $awIdentity, $tables),
    EstabCategoryAuthorizationException::class,
    'slash-bearing function used as dynamic table identifier'
);

$assert(!estab_category_can_manage_master($identity, 'S2'), 'S1 gained master management');
$redcopyIdentity = $identity;
$redcopyIdentity['funktion'] = 'S2';
$assert(estab_category_can_manage_master($redcopyIdentity, 'S2'), 'red-copy denied master management');
$viewerIdentity = $identity;
$viewerIdentity['funktion'] = 'Si';
$assert(estab_category_can_manage_master($viewerIdentity, 'S2'), 'Si denied master management');
$throws(
    static fn () => estab_category_require_management('master', $identity, 'S2'),
    EstabCategoryAuthorizationException::class,
    'S1 master management accepted'
);
estab_category_require_management('fkt', $identity, 'S2');
estab_category_require_management('user', $identity, 'S2');

$filterSession = [
    'fk_katego' => 7,
    'fk_kategotyp' => 'fkt',
    'ma_katego' => 9,
    'ma_kategotyp' => 'global',
];
estab_category_clear_session_filter($filterSession, 'fkt');
$assert(
    !isset($filterSession['fk_katego'], $filterSession['fk_kategotyp'])
        && isset($filterSession['ma_katego'], $filterSession['ma_kategotyp']),
    'deleting a category did not clear only its stale list filter'
);

$assert(
    estab_category_resolve_selection(
        ['category_fkt_oben' => '7', 'category_fkt_unten' => '5'],
        'fkt',
        5
    ) === ['present' => true, 'value' => 7],
    'changed upper select not resolved'
);
$assert(
    estab_category_resolve_selection(
        ['category_user_oben' => '', 'category_user_unten' => '9'],
        'user',
        9
    ) === ['present' => true, 'value' => null],
    'empty selection does not remove assignment'
);
$assert(
    estab_category_resolve_selection([], 'master', 4)
        === ['present' => false, 'value' => 4],
    'absent master controls were treated as an assignment'
);
$throws(
    static fn () => estab_category_resolve_selection(
        ['category_fkt_oben' => '7', 'category_fkt_unten' => '8'],
        'fkt',
        5
    ),
    EstabCategoryConflictException::class,
    'contradictory duplicated controls accepted'
);

$root = dirname(__DIR__, 2);
$helper = file_get_contents($root . '/app/category.php');
$endpoint = file_get_contents($root . '/4fach/katgoedt.php');
$facade = file_get_contents($root . '/4fach/katego.php');
$form = file_get_contents($root . '/4fach/4fachform.php');
$officialForm = file_get_contents($root . '/4fach/official_message_form.php');
$list = file_get_contents($root . '/4fach/liste.php');
$mainController = file_get_contents($root . '/4fach/mainindex.php');
$workflow = file_get_contents($root . '/app/workflow.php');
$apache = file_get_contents($root . '/docker/apache/estab.conf');
foreach ([$helper, $endpoint, $facade, $form, $officialForm, $list, $mainController, $workflow, $apache] as $source) {
    $assert(is_string($source), 'category security source file is unreadable');
}
$identityLockStart = strpos($helper, 'function estab_category_lock_operational_identity(');
$messageLockStart = strpos($helper, 'function estab_category_lock_authorized_message(');
$mutationStart = strpos($helper, 'function estab_category_mutate_authorized(');
$assignmentStart = strpos($helper, 'function estab_category_assign(');
$assert(
    is_int($identityLockStart)
        && is_int($messageLockStart)
        && is_int($mutationStart)
        && is_int($assignmentStart)
        && $identityLockStart < $messageLockStart
        && $messageLockStart < $mutationStart
        && $mutationStart < $assignmentStart,
    'locked category authorisation functions are missing or out of order'
);
$identityLockSource = is_int($identityLockStart) && is_int($messageLockStart)
    ? substr($helper, $identityLockStart, $messageLockStart - $identityLockStart)
    : '';
$mutationSource = is_int($mutationStart) && is_int($assignmentStart)
    ? substr($helper, $mutationStart, $assignmentStart - $mutationStart)
    : '';

$assert(
    substr_count($helper, '->prepare(') >= 12
        && substr_count($helper, '->bind_param(') >= 10
        && substr_count($helper, '->begin_transaction()') >= 3
        && substr_count($helper, 'if (!$connection->begin_transaction())') >= 3
        && substr_count($helper, 'if (!$connection->commit())') >= 3
        && substr_count($helper, '->rollback()') >= 3
        && str_contains($helper, 'estab_incident_with_active_write(')
        && str_contains(
            $helper,
            'WHERE `00_lfd` = ? AND `einsatz_id` = ? FOR UPDATE'
        )
        && str_contains($helper, 'estab_auth_table('),
    'category storage is not consistently prepared, transactional and identifier-safe'
);
$assert(
    !str_contains($helper, 'mysql_query(')
        && !str_contains($facade, 'mysql_query(')
        && !str_contains($endpoint, 'mysql_query(')
        && !str_contains($facade, '$_GET')
        && !str_contains($facade, '$_POST'),
    'legacy/request-driven SQL remains in the category implementation'
);
$assert(
    str_contains($endpoint, 'estab_auth_session_identity($_SESSION)')
        && str_contains(
            $endpoint,
            'estab_read_require_operational_scope('
        )
        && substr_count($endpoint, 'estab_read_message(') >= 2
        && str_contains($endpoint, 'estab_csrf_require_post($_SERVER, $_POST)')
        && str_contains($endpoint, "['create', 'update', 'delete', 'assign']")
        && str_contains($endpoint, 'estab_category_identity_for_function(')
        && str_contains($endpoint, 'estab_category_mutate_authorized(')
        && !preg_match(
            '/estab_category_(?:create|update|delete)\s*\(/',
            $endpoint
        )
        && str_contains($endpoint, "\$_POST['acting_function']")
        && str_contains($endpoint, "\$_GET['acting_function']")
        && str_contains($endpoint, "header('Location: ' . \$location, true, 303)")
        && !str_contains($endpoint, "\$_GET['category_action']"),
    'category endpoint lacks session, CSRF, POST allow-list or PRG enforcement'
);
$assert(
    str_contains($endpoint, 'estab_category_require_management($type, $identity, $redcopy)')
        && str_contains(
            $helper,
            "estab_read_message_allowed_for_identity(\n"
        )
        && str_contains($helper, '$prefix . \'_fkt_\' . $function')
        && str_contains($helper, '$prefix . $function . \'_\' . $code')
        && str_contains($identityLockSource, 'estab_dv_require_operational_account(')
        && str_contains($identityLockSource, 'estab_auth_fetch_additional_functions(')
        && str_contains($identityLockSource, "\$scope['acting_function'] ?? ''")
        && str_contains($identityLockSource, "\$scope['acting_role'] ?? ''")
        && preg_match(
            '/estab_auth_fetch_additional_functions\([^;]+\btrue\s*\)/s',
            $identityLockSource
        ) === 1
        && str_contains(
            $helper,
            'estab_category_redcopy_function('
        )
        && str_contains($helper, '$matrixTable,')
        && str_contains($helper, '$forUpdate ? \' FOR UPDATE\' : \'\'')
        && str_contains(
            $helper,
            "estab_category_require_management(\n"
                . "                    'master',"
        )
        && str_contains(
            $endpoint,
            "(string) \$conf_4f_tbl['empfmtx']"
        ),
    'category authorisation is not revalidated against the locked incident'
);
$assert(
    str_contains($mutationSource, 'return estab_incident_with_active_write(')
        && str_contains(
            $mutationSource,
            'estab_category_lock_operational_identity('
        )
        && str_contains(
            $mutationSource,
            'estab_category_redcopy_function('
        )
        && preg_match(
            '/estab_category_redcopy_function\([^;]+\btrue\s*\)/s',
            $mutationSource
        ) === 1
        && str_contains(
            $mutationSource,
            'estab_category_lock_authorized_message('
        )
        && str_contains(
            $mutationSource,
            'estab_category_insert_in_transaction('
        )
        && str_contains(
            $mutationSource,
            'estab_category_update_in_transaction('
        )
        && str_contains(
            $mutationSource,
            'estab_category_delete_in_transaction('
        ),
    'category CRUD is not committed under one locked authorisation snapshot'
);
$assert(
    str_contains($facade, "name = 'category_' . \$this->dbtyp . '_' . \$position")
        && str_contains($facade, 'estab_category_route_identity(')
        && str_contains($facade, "estab_auth_html(\$row['kategorie'])")
        && str_contains($form, 'name=\\"category_action\\"')
        && str_contains($form, 'value=\\"assign\\"')
        && str_contains($form, 'formaction=\\"katgoedt.php\\"')
        && str_contains(
            $form,
            '$actingFunction = (string) $katego_master->stab_fkt;'
        )
        && str_contains($form, '"acting_function" => $actingFunction')
        && str_contains(
            $form,
            '$berechtigt = estab_category_can_manage_master ('
        )
        && !str_contains($form, '$_SESSION ["vStab_funktion"]')
        && substr_count($form, 'estab_auth_html ($katearr_') === 3,
    'four-part form does not bind category management to the effective actor, '
        . 'safe ID selects, raw category escaping and direct POST assignment'
);
$assert(
    str_contains($officialForm, "['acting_function' => \$actingFunction]")
        && str_contains($officialForm, "formaction=\"' . estab_message_html(\$assignmentAction)")
        && str_contains(
            $officialForm,
            '<input type="hidden" name="acting_function"'
        )
        && str_contains(
            $officialForm,
            '$actingFunction = $this->official_message_acting_function();'
        )
        && str_contains(
            $workflow,
            'function estab_workflow_identity_for_acting_function('
        )
        && str_contains(
            $workflow,
            'estab_workflow_requested_acting_function($request)'
        ),
    'category assignment or message continuation lost the server-validated '
        . 'acting-function scope'
);
$assert(
    str_contains($list, '"ma_ktgo" => $this->masterresult[$i]["lfd"]')
        && str_contains($list, '"fk_ktgo" => $this->fktresult[$i]["lfd"]')
        && str_contains($list, '"us_ktgo" => $this->userresult[$i]["lfd"]')
        && str_contains($list, 'estab_category_scope ((string) $table, $identity, $conf_4f_tbl)')
        && str_contains($list, 'estab_category_fetch_all ($this->connection (), $this->categoryScope)')
        && !str_contains($list, 'mysql_query (')
        && !str_contains($list, 'mysql_query(')
        && str_contains($list, '$where[] = $alias."c.`lfd` = ?"')
        && str_contains($list, '$parameters[] = $categoryId')
        && str_contains($mainController, 'estab_workflow_category_filter'),
    'category list/navigation/filter still uses legacy or name-derived SQL'
);
$assert(
    !preg_match(
        '~LocationMatch[^\\n]*katgoedt|<Location\\s+["\']?/4fach/katgoedt\\.php~i',
        $apache
    ),
    'active category endpoint is blocked by Apache'
);

printf("Category security tests: OK (%d assertions)\n", $assertions);
