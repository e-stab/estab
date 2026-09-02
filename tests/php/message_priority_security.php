<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/message_repository.php';

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

$labels = [
    '' => 'keine',
    'eee' => 'keine',
    'sss' => 'Sofort',
    'bbb' => 'Blitz',
    'aaa' => 'Staatsnot',
];
$documentLabels = [
    '' => '',
    'eee' => '',
    'sss' => 'Sofort',
    'bbb' => 'Blitz',
    'aaa' => 'Staatsnot',
];
$ranks = [
    '' => 0,
    'eee' => 0,
    'sss' => 1,
    'bbb' => 2,
    'aaa' => 3,
];
foreach ($labels as $stored => $label) {
    $assert(
        estab_message_priority_storage_value($stored) === $stored
            && estab_message_priority_is_valid($stored),
        'A supported priority no longer passes the strict storage boundary: '
            . var_export($stored, true)
    );
    $assert(
        estab_message_priority_label($stored) === $label
            && estab_message_priority_document_label($stored)
                === $documentLabels[$stored],
        'A priority was not translated consistently: '
            . var_export($stored, true)
    );
    $assert(
        estab_message_priority_rank($stored) === $ranks[$stored]
            && estab_message_priority_is_urgent($stored)
                === ($ranks[$stored] > 0)
            && estab_message_priority_requires_attention($stored)
                === ($ranks[$stored] > 0),
        'A priority has the wrong processing rank or attention state: '
            . var_export($stored, true)
    );
    $assert(
        estab_message_fields(['09_vorrangstufe' => $stored])
            === ['09_vorrangstufe' => $stored],
        'The repository changed a valid evidence-bearing priority code'
    );
}

foreach (
    [
        null,
        false,
        0,
        [],
        new stdClass(),
        'AAA',
        ' aaa',
        "aaa\n",
        'aaa,bbb',
        'keine',
        'Sofort',
        'Blitz',
        'Staatsnot',
        "\0",
    ] as $invalid
) {
    $assert(
        estab_message_priority_storage_value($invalid) === null
            && !estab_message_priority_is_valid($invalid),
        'Malformed priority input passed the strict storage boundary'
    );
    $expectInvalidInput(
        static fn (): array => estab_message_fields([
            '09_vorrangstufe' => $invalid,
        ]),
        'The repository accepted malformed priority input'
    );
}

$assert(
    estab_message_priority_label('aaa,bbb') === 'unbekannt'
        && estab_message_priority_document_label('aaa,bbb') === 'unbekannt'
        && estab_message_priority_rank('aaa,bbb') === -1
        && estab_message_priority_requires_attention('aaa,bbb'),
    'Malformed stored data can disappear as an ordinary non-priority message'
);

$options = estab_message_priority_options();
$assert(
    array_column($options, 'value') === ['', 'sss', 'bbb', 'aaa']
        && array_column($options, 'label')
            === ['keine', 'Sofort', 'Blitz', 'Staatsnot'],
    'New-message options do not expose the prescribed human labels'
);
$assert(
    !in_array('eee', array_column($options, 'value'), true),
    'The historic eee representation is still offered for new messages'
);
$warning = estab_message_priority_warning('aaa');
$assert(
    $warning !== ''
        && str_contains($warning, 'ausdrückliche Weisung')
        && estab_message_priority_warning('bbb') === ''
        && ($options[3]['warning'] ?? '') === $warning,
    'The organisational Staatsnot warning is missing or applied too broadly'
);

$rankSql = estab_message_priority_order_sql('`09_vorrangstufe`');
$qualifiedRankSql = estab_message_priority_order_sql(
    'm.`09_vorrangstufe`'
);
foreach (
    [
        "WHEN 'aaa' THEN 3",
        "WHEN 'bbb' THEN 2",
        "WHEN 'sss' THEN 1",
        "WHEN 'eee' THEN 0",
        "WHEN '' THEN 0",
        'ELSE -1',
    ] as $rankContract
) {
    $assert(
        str_contains($rankSql, $rankContract)
            && str_contains($qualifiedRankSql, $rankContract),
        'The database queue rank lacks: ' . $rankContract
    );
}
$expectInvalidInput(
    static fn (): string => estab_message_priority_order_sql(
        'request.`09_vorrangstufe`'
    ),
    'A request-derived priority column entered the SQL expression'
);

$sources = [
    'bootstrap' => (string) file_get_contents($root . '/app/bootstrap.php'),
    'validation' => (string) file_get_contents($root . '/4fach/vali_data.php'),
    'form' => (string) file_get_contents($root . '/4fach/4fachform.php'),
    'list' => (string) file_get_contents($root . '/4fach/liste.php'),
    'overview' => (string) file_get_contents($root . '/4fueltg/ue_ltg.php'),
    'message-list-filter' => (string) file_get_contents(
        $root . '/app/message_list.php'
    ),
    'message-list-ui' => (string) file_get_contents(
        $root . '/app/message_list_ui.php'
    ),
    'logoff' => (string) file_get_contents($root . '/4fach/logoff.php'),
    'pdf' => (string) file_get_contents($root . '/4fbak/backup_pdf.php'),
    'runtime' => (string) file_get_contents(
        $root . '/docker/app/verify-runtime-surface.sh'
    ),
    'schema' => (string) file_get_contents(
        $root . '/docker/db/init/10-schema.sql'
    ),
    'evidence' => (string) file_get_contents(
        $root . '/app/message_evidence.php'
    ),
    'export' => (string) file_get_contents($root . '/app/incident_export.php'),
];

$assert(
    str_contains($sources['bootstrap'], "message_priority.php")
        && str_contains($sources['runtime'], 'app/message_priority.php'),
    'The priority boundary is absent from bootstrap or the runtime image'
);
$assert(
    str_contains(
        $sources['validation'],
        'estab_message_priority_storage_value'
    )
        && str_contains(
            $sources['validation'],
            '$this->validate ["09_vorrangstufe"]'
        ),
    'Legacy request validation does not enforce the strict priority boundary'
);
$assert(
    str_contains($sources['form'], 'estab_message_priority_options')
        && str_contains($sources['form'], 'estab_message_priority_warning')
        && str_contains($sources['form'], 'estab_message_priority_label'),
    'The operational message form bypasses the central priority presentation'
);
/*
 * Die Zahlen sind gesunken, weil drei Listenzweige verschwunden sind.
 *
 * liste.php trug drei Nachweisungszweige, die niemand mehr aufrief; sie
 * sind geloescht (siehe ges_tabelle_einheitlich, "kein Listenzweig ohne
 * Aufrufer"). Mit ihnen fielen je ein Rangausdruck und eine Beschriftung
 * weg.
 *
 * Die Anforderung ist damit nicht kleiner geworden, sondern verteilt:
 * Die lebende Nachweisung liegt in app/nachweisung.php und stuft ihre
 * Vorrangspalte ueber die Spaltenart "vorrang" ein -- das Bauteil
 * sortiert danach nach Dringlichkeit, nicht alphabetisch. Beide Haelften
 * werden geprueft; nur die erste hiesse, dass die Nachweisung ihre
 * Vorrangstufen selbst erfinden duerfte.
 */
$assert(
    substr_count(
        $sources['list'],
        'estab_message_priority_order_sql'
    ) >= 4
        && substr_count(
            $sources['list'],
            'estab_message_priority_label'
        ) >= 5
        && str_contains(
            $sources['list'],
            'estab_message_priority_requires_attention'
        ),
    'Operational lists lack explicit ranks, translated labels or highlighting'
);
$nachweisung = file_get_contents($root . '/app/nachweisung.php');
$assert(
    is_string($nachweisung)
        && str_contains($nachweisung, 'estab_message_priority_label')
        && str_contains($nachweisung, "'art' => 'vorrang'")
        && str_contains($nachweisung, "'filter' => ['Sofort', 'Blitz', 'Staatsnot']"),
    'Die Nachweisung stuft ihre Vorrangstufen nicht zentral ein. '
        . 'Alphabetisch sortiert stuende Blitz vor Staatsnot.'
);
$assert(
    str_contains(
        $sources['list'],
        '" ORDER BY ".'
            . "\n        estab_message_priority_order_sql "
            . '("m.`09_vorrangstufe`").'
            . "\n        \" DESC, COALESCE(\"."
            . 'estab_message_list_tbb_number_sql ("m")'
        )
        && str_contains(
            $sources['message-list-filter'],
            'estab_message_priority_order_sql('
        ),
    'General message views do not sort priority before the canonical TTB number'
);
$assert(
    str_contains($sources['overview'], 'estab_message_list_order_sql')
        && str_contains(
            $sources['overview'],
            'estab_message_list_render_table'
        )
        && str_contains(
            $sources['message-list-filter'],
            'estab_message_priority_order_sql'
        )
        && str_contains(
            $sources['message-list-ui'],
            'estab_message_priority_options'
        )
        && str_contains(
            $sources['message-list-ui'],
            'estab_message_priority_label'
        )
        && str_contains(
            $sources['message-list-ui'],
            'estab_message_priority_requires_attention'
        ),
    'The leadership overview bypasses the central priority contract'
);
$assert(
    str_contains($sources['logoff'], 'estab_message_priority_order_sql')
        && str_contains($sources['logoff'], 'estab_message_priority_label')
        && str_contains(
            $sources['logoff'],
            'estab_message_priority_requires_attention'
        ),
    'The asynchronous/debug list bypasses the priority contract'
);
$assert(
    str_contains(
        $sources['pdf'],
        'estab_message_priority_document_label'
    ),
    'Standalone and dossier message PDFs still receive raw priority codes'
);

// These storage contracts are intentionally retained. Changing historic
// evidence or raw export values would invalidate terminal message hashes.
$assert(
    str_contains(
        $sources['schema'],
        "`09_vorrangstufe` SET('eee','sss','bbb','aaa')"
    )
        && str_contains($sources['evidence'], "'09_vorrangstufe'")
        && str_contains($sources['export'], '`09_vorrangstufe`'),
    'The evidence-bearing raw priority storage contract changed'
);

echo 'Message priority security: OK (' . $assertions
    . " assertions)\n";
