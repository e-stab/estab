<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/read_authorization.php';

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
$expectReadDenial = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (EstabReadPermissionException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$expectInvalidInput = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (InvalidArgumentException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$identity = static fn (
    string $code,
    string $function,
    string $role
): array => [
    'benutzer' => $function . ' Vorschlagstest',
    'kuerzel' => $code,
    'funktion' => $function,
    'rolle' => $role,
];

$aw = $identity('aw0001', 'A/W', 'Fernmelder');
$ldf = $identity('ldf001', 'LdF', 'Fernmelder');
$s2 = $identity('s20001', 'S2', 'Stab');
$s2WithTelecommunicationsGrant = $s2 + [
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_LOOSE,
    'estab_additional_functions' => [
        ['funktion' => 'A/W', 'rolle' => 'Fernmelder'],
    ],
];

$assert(
    estab_read_message_suggestion_policy(
        $aw,
        '05_gegenstelle'
    ) === ['direction' => null],
    'A/W lost incident callsign suggestions'
);
$assert(
    estab_read_message_suggestion_policy(
        $ldf,
        '05_gegenstelle'
    ) === ['direction' => null],
    'LdF lost incident callsign suggestions'
);
$assert(
    estab_read_message_suggestion_policy(
        $s2WithTelecommunicationsGrant,
        '05_gegenstelle'
    ) === ['direction' => null],
    'LOOSE explicit A/W additional function lost incident callsign suggestions'
);
$assert(
    estab_read_message_suggestion_policy(
        $ldf,
        '13_abseinheit'
    ) === ['direction' => 'E'],
    'LdF sender suggestions are not limited to incoming messages'
);
$assert(
    estab_read_ldf_mapping_policy($ldf, 'E') === [
        'message_context' => '`05_gegenstelle`',
        'message_target' => '`13_abseinheit`',
        'plan_context' => '`rufname`',
        'plan_target' => '`betriebsstelle`',
    ],
    'incoming LdF callsign-to-sender mapping changed'
);
$assert(
    estab_read_ldf_mapping_policy($ldf, 'A') === [
        'message_context' => '`10_anschrift`',
        'message_target' => '`05_gegenstelle`',
        'plan_context' => '`betriebsstelle`',
        'plan_target' => '`rufname`',
    ],
    'outgoing LdF destination-to-callsign mapping changed'
);
$expectReadDenial(
    static fn (): array => estab_read_ldf_mapping_policy($aw, 'E'),
    'A/W gained LdF pair mappings'
);
$expectReadDenial(
    static fn (): array => estab_read_ldf_mapping_policy(
        $identity('ldf003', 'LdF', 'Stab'),
        'A'
    ),
    'a forged LdF role gained pair mappings'
);
$expectInvalidInput(
    static fn (): array => estab_read_ldf_mapping_policy($ldf, 'X'),
    'an unknown LdF mapping direction was accepted'
);

$strictCapabilityScope = estab_read_effective_capability_scope(
    $ldf + ['duty_assignment_id' => 73],
    'FERNMELDEBETRIEB'
);
$looseCapabilityScope = estab_read_effective_capability_scope(
    array_replace($s2WithTelecommunicationsGrant, [
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
        'authorization_account_function' => 'S2',
        'authorization_account_role' => 'Stab',
    ]),
    'BEFOERDERUNG'
);
$assert(
    str_contains(
        $strictCapabilityScope['sql'],
        "`incident`.`estab_permission_mode` = BINARY 'STRICT'"
    )
        && str_contains(
            $strictCapabilityScope['sql'],
            'duty.`dienstbesetzung_id` = ?'
        )
        && str_contains(
            $strictCapabilityScope['sql'],
            "duty_shift.`status` = 'AKTIV'"
        )
        && str_contains(
            $strictCapabilityScope['sql'],
            "duty.`status` = 'ANGENOMMEN'"
        )
        && $strictCapabilityScope['params'] === [
            'LdF', 'Fernmelder', 'FERNMELDEBETRIEB', 73,
            'LdF', 'Fernmelder', 'LdF', 'Fernmelder',
            'LdF', 'Fernmelder',
        ],
    'suggestion capability scope lost the exact STRICT duty-assignment proof'
);
$assert(
    str_contains(
        $looseCapabilityScope['sql'],
        "`incident`.`estab_permission_mode` = BINARY 'LOOSE'"
    )
        && str_contains(
            $looseCapabilityScope['sql'],
            '`account`.`funktion` = BINARY ?'
        )
        && str_contains(
            $looseCapabilityScope['sql'],
            'FROM `nv_benutzer_zusatzfunktionen` AS extra'
        )
        && $looseCapabilityScope['params'] === [
            'A/W', 'Fernmelder', 'BEFOERDERUNG', 0,
            'A/W', 'Fernmelder', 'A/W', 'Fernmelder',
            'A/W', 'Fernmelder',
        ],
    'suggestion capability scope lost the exact LOOSE account/grant proof'
);
foreach (
    [
        [$aw, '13_abseinheit', 'A/W gained sender suggestions'],
        [$s2, '05_gegenstelle', 'S2 gained callsign suggestions'],
        [$s2, '13_abseinheit', 'S2 gained sender suggestions'],
        [$ldf, '12_inhalt', 'an arbitrary message column became suggestible'],
        [
            $identity('ldf002', 'LdF', 'Stab'),
            '05_gegenstelle',
            'a forged LdF role gained callsign suggestions',
        ],
        [
            $identity('aw0002', 'A/W', 'Stab'),
            '05_gegenstelle',
            'a forged A/W role gained callsign suggestions',
        ],
    ] as [$deniedIdentity, $field, $message]
) {
    $expectReadDenial(
        static fn (): array => estab_read_message_suggestion_policy(
            $deniedIdentity,
            $field
        ),
        $message
    );
}

$assert(
    estab_read_normalize_message_suggestion(
        '  Funkzentrale   Nord  '
    ) === 'Funkzentrale Nord',
    'ordinary suggestion whitespace is not canonicalized'
);
$assert(
    estab_read_normalize_message_suggestion(
        ' Rufname & <Leitstelle> "Süd" '
    ) === 'Rufname & <Leitstelle> "Süd"',
    'valid UTF-8 and HTML-special text was corrupted'
);
$assert(
    estab_read_normalize_message_suggestion('&amp;lt;') === '&lt;',
    'legacy entities were decoded more than once before rendering'
);
foreach ([null, '', " \t\r\n ", [], new stdClass()] as $emptyValue) {
    $assert(
        estab_read_normalize_message_suggestion($emptyValue) === null,
        'an empty or non-scalar suggestion was retained'
    );
}
$assert(
    estab_read_normalize_message_suggestion("Funk\tNord") === null
        && estab_read_normalize_message_suggestion("Funk\nNord") === null
        && estab_read_normalize_message_suggestion("Funk\0Nord") === null,
    'a Unicode control character survived suggestion normalization'
);
$assert(
    estab_read_normalize_mapping_context(
        "  THW FGr N ONEU\r\n  Einsatzabschnitt  "
    ) === 'THW FGr N ONEU Einsatzabschnitt',
    'legitimate multiline mapping context is not compacted safely'
);
$assert(
    estab_read_normalize_mapping_context("THW\0ONEU") === null,
    'a forbidden mapping-context control character survived'
);
$mappingSql = estab_read_mapping_normalized_sql(
    'candidate.`05_gegenstelle`'
);
$assert(
    str_contains($mappingSql, 'LOWER(TRIM(REGEXP_REPLACE(')
        && str_contains($mappingSql, "'[[:space:]]+'")
        && str_contains($mappingSql, "'&quot;'")
        && str_contains($mappingSql, "'&lt;'")
        && str_contains($mappingSql, "'&gt;'")
        && str_contains($mappingSql, "'&nbsp;'")
        && str_contains($mappingSql, "'&amp;'"),
    'mapping SQL does not normalize whitespace and one-pass legacy entities'
);
$expectInvalidInput(
    static fn (): string => estab_read_mapping_normalized_sql(
        'request.`browser_controlled_column`'
    ),
    'mapping SQL accepted a browser-controlled context expression'
);

$root = dirname(__DIR__, 2);
$readSource = (string) file_get_contents(
    $root . '/app/read_authorization.php'
);
$formSource = (string) file_get_contents(
    $root . '/4fach/4fachform.php'
);
$ciSource = (string) file_get_contents(
    $root . '/tests/integration/ci.sh'
);
$browserSource = (string) file_get_contents(
    $root . '/tests/browser/headless_ui.py'
);
$browserFixtureSource = (string) file_get_contents(
    $root . '/tests/integration/message_suggestion_browser_fixture.php'
);
$mappingFunctionStart = strpos(
    $readSource,
    'function estab_read_ldf_mapping_suggestions('
);
$mappingFunctionEnd = $mappingFunctionStart === false
    ? false
    : strpos(
        $readSource,
        'function estab_read_message_suggestions(',
        $mappingFunctionStart
    );
$mappingFunctionSource = is_int($mappingFunctionStart)
    && is_int($mappingFunctionEnd)
    && $mappingFunctionEnd > $mappingFunctionStart
    ? substr(
        $readSource,
        $mappingFunctionStart,
        $mappingFunctionEnd - $mappingFunctionStart
    )
    : '';
$assert(
    $mappingFunctionSource !== '',
    'LdF mapping function source could not be isolated'
);

foreach (
    [
        'function estab_read_message_suggestion_policy(',
        'function estab_read_ldf_mapping_policy(',
        'function estab_read_normalize_message_suggestion(',
        'function estab_read_normalize_mapping_context(',
        'function estab_read_mapping_normalized_sql(',
        'function estab_read_ldf_mapping_suggestions(',
        'function estab_read_message_suggestions(',
        'function estab_read_effective_capability_scope(',
        'estab_read_require_operational_scope(',
        'estab_read_effective_capability_scope(',
        'estab_message_table($messageTable)',
        'JOIN `nv_benutzer` AS account',
        'JOIN `nv_einsatz_status` AS active',
        'active.`active_einsatz_id` = ?',
        'BINARY account.`benutzer` = BINARY ?',
        'account.`aktiv` = 1',
        'account.`estab_gesperrt` = 0',
        'candidate.`einsatz_id` = active.`active_einsatz_id`',
        'candidate.`einsatz_id` = ?',
        'candidate.`04_richtung` = ?',
        'GROUP BY BINARY TRIM(candidate.',
        'ORDER BY message.`00_lfd` DESC',
        '$statement->bind_result($storedSuggestion)',
    ] as $requiredReadContract
) {
    $assert(
        str_contains($readSource, $requiredReadContract),
        'message suggestion read boundary lacks: ' . $requiredReadContract
    );
}
$assert(
    str_contains($readSource, "'05_gegenstelle'")
        && str_contains($readSource, "'13_abseinheit'")
        && str_contains($readSource, "'direction' => 'E'"),
    'suggestion query does not use the fixed field/direction allowlist'
);
$assert(
    substr_count(
        $readSource,
        "FROM (' . \$scopeSql . ') AS scope"
    ) === 2
        && str_contains(
            $readSource,
            "current_message.`x00_status` = 1"
        )
        && str_contains(
            $readSource,
            "current_message.`x02_sperre` IN ('t', '1')"
        )
        && str_contains(
            $readSource,
            "current_message.`x03_sperruser`"
        ),
    'mapping query does not rejoin the exact locked current message in both sources'
);
$assert(
    str_contains($readSource, "candidate.`x00_status` = 8")
        && str_contains(
            $readSource,
            "candidate.`x01_abschluss` IN ('t', '1')"
        )
        && str_contains(
            $readSource,
            "telecom_plan.`status` = 'AKTIV'"
        )
        && str_contains($readSource, "telecom_plan.`gueltig_ab` <= NOW()")
        && str_contains($readSource, "telecom_plan.`gueltig_bis` >= NOW()"),
    'mapping sources are not limited to completed pairs and a valid active S6 plan'
);
$assert(
    strpos($readSource, "'message' AS `source_kind`")
        < strpos($readSource, "'plan' AS `source_kind`")
        && str_contains(
            $readSource,
            "ORDER BY mapped.`source_priority` ASC"
        ),
    'completed message mappings are not ranked before S6-plan mappings'
);
$assert(
    str_contains($readSource, 'CAST(')
        && str_contains($readSource, ' AS BINARY)')
        && str_contains($readSource, "REGEXP_REPLACE(")
        && str_contains($readSource, '$direction === \'A\'')
        && str_contains($readSource, "'match' => \$storedMatch")
        && str_contains(
            $readSource,
            "'matched_context' => \$matchedContext"
        )
        && !str_contains($mappingFunctionSource, 'LOCATE('),
    'mapping comparison is not binary/exact with explicit outgoing-only '
        . 'related-match evidence'
);

foreach (
    [
        'estab-message-callsign-suggestions',
        'estab-message-sender-suggestions',
        'data-estab-incident-suggestions=\\"',
        'data-estab-suggestion-listbox=\\"',
        'list=\\"',
        'autocomplete=\\"off\\"',
        'role=\\"combobox\\"',
        'role=\\"listbox\\"',
        'aria-describedby=\\"',
        'data-estab-message-suggestion-picker',
        'data-estab-suggestion-value=\\"',
        'data-estab-mapping-match=\\"',
        'data-estab-mapping-quality=\\"',
        'Bestätigtes Nachrichtenpaar',
        'Aktiver S6-Fernmeldeplan',
        'Ähnlich',
        'Bezug:',
        'input.removeAttribute("list")',
        'event.key === "ArrowDown"',
        'event.key === "Escape"',
    ] as $requiredMarkup
) {
    $assert(
        str_contains($formSource, $requiredMarkup),
        'message form lacks native accessible suggestion markup: '
            . $requiredMarkup
    );
}
$assert(
    (
        str_contains($formSource, 'estab_auth_html ($suggestion)')
        || str_contains($formSource, 'estab_auth_html($suggestion)')
    )
        && !str_contains(
            $formSource,
            'estab_message_html ($suggestion)'
        ),
    'normalized suggestions are not escaped without a second entity decode'
);
$assert(
    str_contains($formSource, '"FM-Eingang"')
        && str_contains($formSource, '"FM-Eingang_Anhang"')
        && str_contains($formSource, '"LdF-Eingang"')
        && str_contains($formSource, '"LdF-Ausgang"'),
    'suggestion controls do not cover all intended telecommunications tasks'
);
$assert(
    str_contains($formSource, 'estab_read_ldf_mapping_suggestions (')
        && str_contains(
            $formSource,
            'option.getAttribute("data-estab-suggestion-value")'
        )
        && str_contains($formSource, 'Freie Eingabe bleibt möglich.'),
    'LdF mapping results are not safely selectable while preserving free input'
);
$assert(
    str_contains($formSource, 'estab_read_message_suggestions (')
        || str_contains($formSource, 'estab_read_message_suggestions('),
    'message controller does not load incident suggestions through the read boundary'
);
$assert(
    !str_contains(
        $formSource,
        'SELECT DISTINCT `05_gegenstelle`'
    )
        && !str_contains(
            $formSource,
            'SELECT DISTINCT `13_abseinheit`'
        ),
    'message controller bypasses the central suggestion policy with direct SQL'
);
$assert(
    substr_count(
        $ciSource,
        'estab_message_suggestions_ci_test'
    ) >= 2,
    'isolated suggestion database is not both allowlisted and executed by CI'
);
$assert(
    str_contains($ciSource, '--message-suggestions')
        && str_contains(
            $browserSource,
            'def run_message_suggestions(self)'
        )
        && str_contains(
            $browserSource,
            '"Input.dispatchKeyEvent"'
        )
        && str_contains(
            $browserFixtureSource,
            'ESTAB_MESSAGE_SUGGESTION_BROWSER_FIXTURE'
        ),
    'real A/W browser focus and keyboard acceptance is not wired fail-closed'
);

echo 'message suggestion security: OK (' . $assertions
    . " assertions)\n";
