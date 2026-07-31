<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/logbook.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$validTitle = estab_logbook_validate_title([
    'einsatz' => 'Hochwasser Süd',
    'ort' => 'Kreisgebiet & Innenstadt',
]);
$assert($validTitle['valid'] === true, 'valid Unicode title rejected');
$assert(
    estab_logbook_validate_title(['einsatz' => '', 'ort' => 'Test'])['valid'] === false,
    'empty operation title accepted'
);
$assert(
    estab_logbook_validate_title([
        'einsatz' => str_repeat('x', ESTAB_LOGBOOK_TITLE_MAX_LENGTH + 1),
        'ort' => 'Test',
    ])['valid'] === false,
    'overlong operation title accepted'
);
$assert(
    estab_logbook_validate_title(['einsatz' => "Test\nInjected", 'ort' => 'Test'])['valid'] === false,
    'control character in single-line title accepted'
);

$validEntry = estab_logbook_validate_entry([
    'event' => "Lageänderung\nzweite Zeile <script>",
    'comment' => 'Rückmeldung & Prüfung',
]);
$assert($validEntry['valid'] === true, 'valid multiline entry rejected');
$assert(
    estab_logbook_validate_entry(['event' => '', 'comment' => ''])[ 'valid'] === false,
    'empty event accepted'
);
$assert(
    estab_logbook_validate_entry([
        'event' => str_repeat('x', ESTAB_LOGBOOK_TEXT_MAX_LENGTH + 1),
        'comment' => '',
    ])['valid'] === false,
    'overlong event accepted'
);
$assert(
    estab_logbook_validate_entry(['event' => ['nested'], 'comment' => ''])['valid'] === false,
    'non-scalar event accepted'
);
$assert(
    estab_auth_html('<script>alert("x")</script>')
        === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
    'logbook HTML boundary does not escape active markup'
);

$root = dirname(__DIR__, 2);
$helper = file_get_contents($root . '/app/logbook.php');
$etb = file_get_contents($root . '/stabetb/etb.php');
$tbb = file_get_contents($root . '/fmtbb/tbb.php');
$httpSmoke = file_get_contents($root . '/tests/integration/http_smoke.sh');
$logbooksHttp = file_get_contents($root . '/tests/integration/logbooks_http.sh');
$categoriesHttp = file_get_contents($root . '/tests/integration/categories_http.sh');
$legacyLoginHttp = file_get_contents($root . '/tests/integration/legacy_login_http.sh');
$messageWorkflowHttp = file_get_contents(
    $root . '/tests/integration/message_workflow_http.sh'
);
if (
    !is_string($helper)
    || !is_string($etb)
    || !is_string($tbb)
    || !is_string($httpSmoke)
    || !is_string($logbooksHttp)
    || !is_string($categoriesHttp)
    || !is_string($legacyLoginHttp)
    || !is_string($messageWorkflowHttp)
) {
    throw new RuntimeException('Could not read logbook source files');
}

$fixtureDefault = static function (
    string $source,
    string $variable,
    string $environment
): string {
    $pattern = '/^'
        . preg_quote($variable, '/')
        . '=\\$\\{'
        . preg_quote($environment, '/')
        . ':-([^}\\r\\n]+)\\}$/m';
    if (preg_match($pattern, $source, $matches) !== 1) {
        throw new RuntimeException(
            "Missing integration fixture {$variable}/{$environment}"
        );
    }

    return $matches[1];
};
$assert(
    $fixtureDefault(
        $httpSmoke,
        'legacy_registration_code',
        'ESTAB_TEST_TBB_CODE'
    ) === $fixtureDefault($logbooksHttp, 'aw_code', 'ESTAB_TEST_TBB_CODE')
        && $fixtureDefault(
            $httpSmoke,
            'legacy_registration_name',
            'ESTAB_TEST_TBB_NAME'
        ) === $fixtureDefault(
            $logbooksHttp,
            'aw_name',
            'ESTAB_TEST_TBB_NAME'
        ),
    'HTTP smoke and logbook integration assign different identities '
        . 'to their shared A/W account'
);
$sharedS1Defaults = [];
foreach (
    [
        [$httpSmoke, 'test'],
        [$categoriesHttp, 's1'],
        [$legacyLoginHttp, 'test'],
    ] as [$source, $prefix]
) {
    $sharedS1Defaults[] = implode('|', [
        $fixtureDefault(
            $source,
            $prefix . '_name',
            'ESTAB_TEST_LOGIN_NAME'
        ),
        $fixtureDefault(
            $source,
            $prefix . '_code',
            'ESTAB_TEST_LOGIN_CODE'
        ),
        $fixtureDefault(
            $source,
            $prefix . '_function',
            'ESTAB_TEST_LOGIN_FUNCTION'
        ),
    ]);
}
$assert(
    count(array_unique($sharedS1Defaults)) === 1,
    'HTTP, category, and legacy-login integrations assign different '
        . 'identities to their shared S1 account'
);
$assert(
    $fixtureDefault(
        $httpSmoke,
        'shift_s2_name',
        'ESTAB_TEST_ETB_NAME'
    ) === $fixtureDefault(
        $logbooksHttp,
        's2_name',
        'ESTAB_TEST_ETB_NAME'
    )
        && $fixtureDefault(
            $httpSmoke,
            'shift_s2_code',
            'ESTAB_TEST_ETB_CODE'
        ) === $fixtureDefault(
            $logbooksHttp,
            's2_code',
            'ESTAB_TEST_ETB_CODE'
        )
        && $fixtureDefault(
            $logbooksHttp,
            's2_name',
            'ESTAB_TEST_ETB_NAME'
        ) === $fixtureDefault(
            $categoriesHttp,
            's2_name',
            'ESTAB_TEST_ETB_NAME'
        )
        && $fixtureDefault(
            $logbooksHttp,
            's2_code',
            'ESTAB_TEST_ETB_CODE'
        ) === $fixtureDefault(
            $categoriesHttp,
            's2_code',
            'ESTAB_TEST_ETB_CODE'
        ),
    'HTTP, logbook, and category integrations assign different identities '
        . 'to their shared S2 account'
);
$assert(
    $fixtureDefault(
        $httpSmoke,
        'shift_si_name',
        'ESTAB_TEST_CATEGORY_SI_NAME'
    ) === $fixtureDefault(
        $categoriesHttp,
        'si_name',
        'ESTAB_TEST_CATEGORY_SI_NAME'
    )
        && $fixtureDefault(
            $httpSmoke,
            'shift_si_code',
            'ESTAB_TEST_CATEGORY_SI_CODE'
        ) === $fixtureDefault(
            $categoriesHttp,
            'si_code',
            'ESTAB_TEST_CATEGORY_SI_CODE'
        ),
    'HTTP and category integrations assign different identities '
        . 'to their shared Si account'
);

$derivedWorkflowPrefixes = [];
foreach (['aw', 'ldf', 'si', 's1', 's2', 's3', 's6', 'pol'] as $function) {
    $pattern = '/^' . preg_quote($function, '/')
        . '_code="([a-z])\\$\\{identity_seed\\}"$/m';
    if (preg_match($pattern, $messageWorkflowHttp, $matches) !== 1) {
        throw new RuntimeException(
            "Missing isolated workflow fixture for {$function}"
        );
    }
    $derivedWorkflowPrefixes[] = $matches[1];
}
$assert(
    count(array_unique($derivedWorkflowPrefixes))
        === count($derivedWorkflowPrefixes)
        && !in_array('e', $derivedWorkflowPrefixes, true),
    'Derived message-workflow identities can collide with each other or '
        . 'with the fixed e2* integration accounts'
);

$assert(
    substr_count($helper, '$connection->prepare(') >= 3
        && substr_count($helper, '->bind_param(') >= 3,
    'logbook database values are not consistently parameterized'
);
$assert(
    str_contains($helper, "WHERE `mtx_rc2` IN ('t', '1')"),
    'ETB red-copy lookup does not support current and historical flags'
);
$assert(
    str_contains($helper, 'ESTAB_LOGBOOK_TITLE_MAX_LENGTH = 255')
        && str_contains($helper, 'ESTAB_LOGBOOK_TEXT_MAX_LENGTH = 10000'),
    'server-side logbook length limits are missing'
);
$assert(
    str_contains($helper, 'estab_auth_table($table)')
        && str_contains($helper, "in_array(\$kind, ['etb', 'tbb'], true)"),
    'dynamic table or column identifiers are not allowlisted'
);
$assert(
    str_contains($helper, 'estab_csrf_require_post($server, $post)'),
    'shared logbook CSRF gate is missing'
);
$assert(
    str_contains($helper, 'function estab_logbook_validate_references(')
        && str_contains($helper, 'FROM `nv_nachrichten`')
        && str_contains($helper, 'FROM `nv_anhang`')
        && str_contains($helper, '`estab_correction_of`')
        && substr_count($helper, 'LIMIT 1 FOR UPDATE') >= 3,
    'ETB references are not locked and checked against the active incident'
);

foreach (['ETB' => $etb, 'TBB' => $tbb] as $name => $source) {
    $prefix = strtolower($name);
    $normalizedMarkupSource = str_replace('\\"', '"', $source);
    $assert(
        preg_match(
            '/<form\b[^>]*\bmethod="post"/i',
            $normalizedMarkupSource
        ) === 1
            && str_contains($source, 'estab_csrf_field ()')
            && str_contains($source, 'estab_logbook_require_csrf ($_SERVER, $_POST)'),
        "{$name} writes are not POST-only and CSRF-protected"
    );
    $assert(
        str_contains($source, 'estab_auth_html (')
            && str_contains($source, 'maxlength=\"10000\"'),
        "{$name} output escaping or browser-side length boundary is missing"
    );
    $assert(
        !str_contains($source, 'query_table_iu')
            && !str_contains($source, 'speichen_' . strtolower($name) . '_eintrag ($_GET)'),
        "{$name} still exposes a legacy interpolated GET write path"
    );
    $assert(
        str_contains($source, 'estab_logbook_abort (403'),
        "{$name} role/CSRF failures do not return HTTP 403"
    );
    $assert(
        str_contains(
            $source,
            'require_once __DIR__ . "/../app/read_authorization.php";'
        )
            && str_contains($source, 'estab_auth_require_session ($_SESSION)')
            && str_contains(
                $source,
                'estab_read_session_identity ($_SESSION)'
            )
            && str_contains(
                $source,
                'estab_read_require_operational_scope ('
            )
            && str_contains($source, 'if ($requestMethod === "POST")')
            && str_contains($source, 'if (!$berechtigt)'),
        "{$name} selected active-hat read/write boundary is missing"
    );
    $assert(
        strpos($source, 'estab_read_require_operational_scope (')
            < strpos($source, 'new ' . strtolower($name) . '_liste'),
        "{$name} reads logbook data before checking the selected active hat"
    );
    $assert(
        str_contains(
            $source,
            'var $' . $prefix . '_einsatz_aktiv = false;'
        )
            && str_contains(
                $source,
                '$this->' . $prefix . '_einsatz_aktiv = true;'
            )
            && str_contains(
                $source,
                'estab_incident_command_post_name ($incident)'
            )
            && str_contains(
                $source,
                'catch (EstabIncidentConfigurationException) {'
            )
            && str_contains($source, 'data-estab-incident-incomplete')
            && substr_count(
                $source,
                'if ($' . $prefix . 'obj->' . $prefix
                    . '_einsatz_aktiv)'
            ) >= 2,
        "{$name} renders an incomplete active incident as writable or active"
    );
    $assert(
        str_contains(
            $source,
            '$' . $prefix . 'obj->' . $prefix . '_authorized ='
        )
            && str_contains(
                $source,
                '$berechtigt && $' . $prefix . 'obj->' . $prefix
                    . '_titel_gesetzt;'
            )
            && str_contains(
                $source,
                'if ($' . $prefix . 'obj->' . $prefix
                    . '_authorized && $entryFormRequested)'
            )
            && !str_contains(
                $source,
                'if ($berechtigt && $entryFormRequested)'
            ),
        "{$name} offers an entry menu while the active incident is incomplete"
    );
    $assert(
        preg_match(
            '/catch \\(EstabIncidentConfigurationException \\$exception\\) \\{'
                . '.*?estab_logbook_abort \\(\\s*409,\\s*'
                . '"Der aktive Einsatz ist unvollständig\\./s',
            $source
        ) === 1,
        "{$name} maps an incident configuration write failure to HTTP 409"
    );
    $assert(
        str_contains($source, 'var $' . $prefix . '_rolle ;')
            && str_contains(
                $source,
                'public int $' . $prefix . '_duty_assignment_id = 0;'
            )
            && str_contains(
                $source,
                '$' . $prefix . 'obj->' . $prefix
                    . '_rolle = $identity ["rolle"];'
            )
            && str_contains(
                $source,
                '$dutyAssignmentId = estab_read_duty_assignment_id ('
            )
            && str_contains(
                $source,
                '$' . $prefix . 'obj->' . $prefix
                    . '_duty_assignment_id = $dutyAssignmentId;'
            )
            && str_contains(
                $source,
                '"rolle" => (string) $this->' . $prefix . '_rolle'
            )
            && str_contains(
                $source,
                '"duty_assignment_id" => $this->' . $prefix
                    . '_duty_assignment_id'
            ),
        "{$name} drops the authenticated role or selected assignment "
            . 'before the capability guard'
    );
    $assert(
        !str_contains($source, '$_POST ["duty_assignment_id"]')
            && !str_contains($source, '$_GET ["duty_assignment_id"]'),
        "{$name} accepts a client-supplied duty assignment"
    );
}

$assert(
    str_contains($etb, 'estab_dv_has_selected_capability (')
        && str_contains($etb, '"EINSATZTAGEBUCH"')
        && !str_contains($etb, '$readauth')
        && !str_contains($etb, 'Keine Berechtigung für das Einsatztagebuch'),
    'ETB selected-hat readers must remain writable only by ETB capability'
);
$assert(
    str_contains($tbb, 'estab_dv_has_selected_capability (')
        && str_contains($tbb, '"BEFOERDERUNG"')
        && !str_contains($tbb, '$readonly')
        && !str_contains($tbb, 'Keine Berechtigung für das technische Betriebsbuch'),
    'TBB selected-hat readers must remain writable only by Beförderung'
);

echo "logbook security: OK ({$assertions} assertions)\n";
