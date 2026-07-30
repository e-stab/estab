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

$identity = static fn (
    string $code,
    string $function,
    string $role
): array => [
    'benutzer' => $function . ' Vorschlagstest',
    'kuerzel' => $code,
    'funktion' => $function,
    'rolle' => $role,
    'duty_assignment_id' => 17,
];

$aw = $identity('aw0001', 'A/W', 'Fernmelder');
$ldf = $identity('ldf001', 'LdF', 'Fernmelder');
$s2 = $identity('s20001', 'S2', 'Stab');

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
        $ldf,
        '13_abseinheit'
    ) === ['direction' => 'E'],
    'LdF sender suggestions are not limited to incoming messages'
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

foreach (
    [
        'function estab_read_message_suggestion_policy(',
        'function estab_read_normalize_message_suggestion(',
        'function estab_read_message_suggestions(',
        'estab_read_require_operational_scope(',
        'estab_message_table($messageTable)',
        'JOIN `nv_dienstbesetzungen` AS assignment',
        'JOIN `nv_funktionsfaehigkeiten` AS capability',
        'JOIN `nv_einsatz_status` AS active',
        'active.`active_einsatz_id` = duty_shift.`einsatz_id`',
        'candidate.`einsatz_id` = duty_shift.`einsatz_id`',
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
