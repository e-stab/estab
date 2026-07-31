<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/message_list.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$recipients = ['S1', 'S2', 'POL', 'THW'];
$defaults = estab_message_list_default_filters();
$assert(
    $defaults === [
        'q' => '',
        'direction' => '',
        'priority' => '',
        'status' => '',
        'from' => '',
        'to' => '',
        'recipient' => '',
        'sort' => 'priority_newest',
        'page' => 1,
        'page_size' => 50,
    ],
    'Message-list defaults changed unexpectedly'
);
$assert(
    estab_message_list_parse_filters([], $recipients) === $defaults,
    'Missing request values did not produce safe defaults'
);

$parsed = estab_message_list_parse_filters([
    'ml_q' => "  Müller 100%_!  ",
    'ml_direction' => 'E',
    'ml_priority' => 'bbb',
    'ml_status' => 'review',
    'ml_from' => '2024-02-29',
    'ml_to' => '2024-03-01',
    'ml_recipient' => 'S2',
    'ml_sort' => 'oldest',
    'ml_page' => '7',
    'ml_page_size' => '100',
], $recipients);
$assert(
    $parsed === [
        'q' => 'Müller 100%_!',
        'direction' => 'E',
        'priority' => 'bbb',
        'status' => 'review',
        'from' => '2024-02-29',
        'to' => '2024-03-01',
        'recipient' => 'S2',
        'sort' => 'oldest',
        'page' => 7,
        'page_size' => 100,
    ],
    'Valid filter request was not normalized deterministically'
);
$assert(
    estab_message_list_parse_filters(
        ['x_q' => "\u{2003} Lage Süd \u{00A0}"],
        $recipients,
        'x_'
    )['q'] === 'Lage Süd',
    'Unicode whitespace was not trimmed from the search'
);
$assert(
    estab_message_list_parse_filters(['ml_q' => '   '], $recipients)['q'] === '',
    'Whitespace-only search remained active'
);
$assert(
    estab_message_list_parse_filters(
        ['ml_q' => str_repeat('ä', 120)],
        $recipients
    )['q'] === str_repeat('ä', 120),
    'The 120-character UTF-8 search limit was treated as a byte limit'
);

$invalidRequests = [
    ['ml_q' => str_repeat('ä', 121)],
    ['ml_q' => "Lage\nSüd"],
    ['ml_q' => "Lage\u{200B}Süd"],
    ['ml_q' => "\xC3\x28"],
    ['ml_q' => ['not', 'scalar']],
    ['ml_direction' => 'X'],
    ['ml_priority' => 'eee'],
    ['ml_status' => 'open'],
    ['ml_from' => '2023-02-29'],
    ['ml_from' => '0000-01-01'],
    ['ml_from' => '0999-12-31'],
    ['ml_from' => '2024-2-01'],
    ['ml_to' => '2024-04-31'],
    ['ml_from' => '2024-04-02', 'ml_to' => '2024-04-01'],
    ['ml_recipient' => 'S3'],
    ['ml_sort' => '12_abfzeit DESC; DROP TABLE nv_nachrichten'],
    ['ml_page' => '0'],
    ['ml_page' => '-1'],
    ['ml_page' => '01'],
    ['ml_page' => '1 OR 1=1'],
    ['ml_page_size' => '15'],
    ['ml_page_size' => '101'],
];
foreach ($invalidRequests as $request) {
    try {
        estab_message_list_parse_filters($request, $recipients);
        $assert(false, 'Malformed message-list request was accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'Malformed message-list request was rejected');
    }
}
foreach ([[''], ["S1\n"], [str_repeat('x', 65)], [['S1']]] as $allowlist) {
    try {
        estab_message_list_parse_filters([], $allowlist);
        $assert(false, 'Malformed recipient allowlist was accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'Malformed recipient allowlist was rejected');
    }
}
foreach (['', '_bad', 'bad-prefix', str_repeat('x', 33)] as $prefix) {
    try {
        estab_message_list_parse_filters([], $recipients, $prefix);
        $assert(false, 'Malformed request prefix was accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'Malformed request prefix was rejected');
    }
}

$current = array_replace($defaults, [
    'q' => 'Brand',
    'direction' => 'E',
    'page' => 4,
]);
$pageOnly = estab_message_list_apply_request(
    $current,
    ['ml_page' => '5'],
    $recipients
);
$assert(
    $pageOnly === array_replace($current, ['page' => 5]),
    'Page-only request changed a filter'
);
$unchangedWithPage = estab_message_list_apply_request(
    $current,
    ['ml_q' => 'Brand', 'ml_direction' => 'E', 'ml_page' => '6'],
    $recipients
);
$assert(
    $unchangedWithPage === array_replace($current, ['page' => 6]),
    'Unchanged submitted filters blocked a page request'
);
$changed = estab_message_list_apply_request(
    $current,
    ['ml_direction' => 'A', 'ml_page' => '9'],
    $recipients
);
$assert(
    $changed['direction'] === 'A'
        && $changed['q'] === 'Brand'
        && $changed['page'] === 1,
    'Changed filter did not retain other filters and reset to page one'
);
$sizeChanged = estab_message_list_apply_request(
    $current,
    ['ml_page_size' => '100'],
    $recipients
);
$assert(
    $sizeChanged['page_size'] === 100 && $sizeChanged['page'] === 1,
    'Changed page size did not reset to page one'
);
$assert(
    estab_message_list_apply_request(
        $current,
        ['unrelated' => 'value'],
        $recipients
    ) === $current,
    'Unrelated request data changed list state'
);
$assert(
    estab_message_list_apply_request(
        $current,
        ['ml_reset' => '1', 'ml_page' => '99'],
        $recipients
    ) === $defaults,
    'Reset did not restore complete defaults'
);
$staleRecipient = array_replace($current, [
    'recipient' => 'REMOVED',
    'page' => 7,
]);
$assert(
    estab_message_list_apply_request(
        $staleRecipient,
        [],
        $recipients
    ) === array_replace($current, ['recipient' => '', 'page' => 1]),
    'Removed recipient function trapped the stored list session'
);
$assert(
    estab_message_list_apply_request(
        ['recipient' => ['corrupt session value']],
        ['ml_reset' => '1'],
        $recipients
    ) === $defaults,
    'Reset parsed a stale server-side state before clearing it'
);
$removed = estab_message_list_apply_request(
    $current,
    ['ml_remove' => 'direction', 'ml_q' => 'must be ignored'],
    $recipients
);
$assert(
    $removed === array_replace($current, ['direction' => '', 'page' => 1]),
    'Filter removal changed more than the selected field and page'
);
$removableFields = [
    'q', 'direction', 'priority', 'status', 'from', 'to', 'recipient',
];
foreach ($removableFields as $field) {
    $populated = [
        'q' => 'Brand',
        'direction' => 'A',
        'priority' => 'aaa',
        'status' => 'done',
        'from' => '2026-01-01',
        'to' => '2026-12-31',
        'recipient' => 'S1',
        'sort' => 'newest',
        'page' => 8,
        'page_size' => 100,
    ];
    $afterRemoval = estab_message_list_apply_request(
        $populated,
        ['ml_remove' => $field],
        $recipients
    );
    $expectedRemoval = $populated;
    $expectedRemoval[$field] = $defaults[$field];
    $expectedRemoval['page'] = 1;
    $assert(
        $afterRemoval === $expectedRemoval,
        'Filter removal did not exclusively clear ' . $field
    );
}
foreach (['sort', 'page_size', 'unknown', '', ['q']] as $remove) {
    try {
        estab_message_list_apply_request(
            $current,
            ['ml_remove' => $remove],
            $recipients
        );
        $assert(false, 'Unknown filter-removal target was accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'Unknown filter-removal target was rejected');
    }
}
try {
    estab_message_list_apply_request($current, ['ml_reset' => []], $recipients);
    $assert(false, 'Array reset value was accepted');
} catch (InvalidArgumentException) {
    $assert(true, 'Array reset value was rejected');
}

$sqlFilters = [
    'q' => '100%_!',
    'direction' => 'A',
    'priority' => 'none',
    'status' => 'done',
    'from' => '2026-07-01',
    'to' => '2026-07-31',
    'recipient' => 'S1',
    'sort' => 'priority_newest',
    'page' => 1,
    'page_size' => 50,
];
$sql = estab_message_list_filter_sql($sqlFilters, 'msg');
$assert(
    str_contains($sql['sql'], 'msg.`04_richtung` = ?')
        && str_contains($sql['sql'], 'msg.`09_vorrangstufe` = ?')
        && str_contains($sql['sql'], 'msg.`x00_status` = ?')
        && str_contains($sql['sql'], 'msg.`12_abfzeit` >= ?')
        && str_contains($sql['sql'], 'msg.`12_abfzeit` < ?')
        && str_contains($sql['sql'], 'msg.`16_empf` REGEXP ?')
        && !str_contains($sql['sql'], 'msg.`04_nummer` = ?')
        && !str_contains($sql['sql'], 'msg.`00_lfd` = ?')
        && !str_contains($sql['sql'], 'MATCH(')
        && substr_count($sql['sql'], "LIKE ? ESCAPE '!'") === 7,
    'Structured or reserved-character LIKE filter SQL is incomplete'
);
$assert(
    $sql['params'][0] === 'A'
        && $sql['params'][1] === ''
        && $sql['params'][2] === 'eee'
        && $sql['params'][3] === 8
        && $sql['params'][4] === '2026-07-01 00:00:00'
        && $sql['params'][5] === '2026-08-01 00:00:00'
        && str_contains((string) $sql['params'][6], 'alle|S1')
        && array_slice($sql['params'], 7)
            === array_fill(0, 7, '%100!%!_!!%'),
    'Filter SQL parameters are not literal, ordered or date-safe'
);
$assert(
    !str_contains($sql['sql'], '100%_!')
        && !str_contains($sql['sql'], '2026-07-01')
        && !str_contains($sql['sql'], 'S1'),
    'A request value was interpolated into filter SQL'
);
$textSql = estab_message_list_filter_sql(array_replace($defaults, [
    'q' => "x' OR 1=1 --",
]));
$assert(
    substr_count($textSql['sql'], "LIKE ? ESCAPE '!'") === 7
        && count($textSql['params']) === 7
        && count(array_unique($textSql['params'])) === 1
        && !str_contains($textSql['sql'], 'OR 1=1 --'),
    'Text search was interpolated or gained numeric-only columns'
);
$fulltextSql = estab_message_list_filter_sql(array_replace($defaults, [
    'q' => 'Brand Müller 123',
]));
$assert(
    str_contains(
        $fulltextSql['sql'],
        'MATCH(m.`05_gegenstelle`,m.`10_anschrift`,m.`11_rufnummer`,'
            . 'm.`12_betreff`,m.`12_inhalt`,m.`13_abseinheit`,'
            . 'm.`14_funktion`) AGAINST (? IN BOOLEAN MODE)'
    )
        && !str_contains($fulltextSql['sql'], "LIKE ? ESCAPE '!'")
        && $fulltextSql['params'] === ['+Brand* +Müller* +123*'],
    'Safe three-character terms did not use the canonical FULLTEXT index'
);
$numericFulltextSql = estab_message_list_filter_sql(array_replace($defaults, [
    'q' => '000123',
]));
$canonicalTbbNumberSql = estab_message_list_tbb_number_sql('m');
$assert(
    str_contains(
        $numericFulltextSql['sql'],
        $canonicalTbbNumberSql . ' = ?'
    )
        && str_contains(
            $canonicalTbbNumberSql,
            'estab_tbb_proof.`einsatz_id` = m.`einsatz_id`'
        )
        && str_contains(
            $canonicalTbbNumberSql,
            'estab_tbb_proof.`estab_message_id` = m.`00_lfd`'
        )
        && str_contains(
            $canonicalTbbNumberSql,
            "BINARY estab_tbb_proof.`estab_entry_type` = BINARY 'nachricht'"
        )
        && str_contains(
            $canonicalTbbNumberSql,
            'ORDER BY estab_tbb_proof.`estab_book_lfd`,'
        )
        && !str_contains($numericFulltextSql['sql'], 'm.`04_nummer` = ?')
        && !str_contains($numericFulltextSql['sql'], 'm.`00_lfd` = ?')
        && str_contains($numericFulltextSql['sql'], 'MATCH(')
        && $numericFulltextSql['params'] === [123, '+000123*'],
    'Numeric search does not use only the canonical incident-local TTB evidence number'
);
$shortNumericSql = estab_message_list_filter_sql(array_replace($defaults, [
    'q' => '12',
]));
$assert(
    str_contains($shortNumericSql['sql'], $canonicalTbbNumberSql . ' = ?')
        && !str_contains($shortNumericSql['sql'], 'm.`04_nummer` = ?')
        && !str_contains($shortNumericSql['sql'], 'm.`00_lfd` = ?')
        && !str_contains($shortNumericSql['sql'], 'MATCH(')
        && substr_count($shortNumericSql['sql'], "LIKE ? ESCAPE '!'") === 7
        && $shortNumericSql['params'][0] === 12
        && array_slice($shortNumericSql['params'], 1)
            === array_fill(0, 7, '%12%'),
    'Short numeric search did not combine the TTB number with literal fallback'
);
$overflowNumeric = str_repeat('9', 120);
$overflowNumericSql = estab_message_list_filter_sql(array_replace($defaults, [
    'q' => $overflowNumeric,
]));
$assert(
    str_contains($overflowNumericSql['sql'], $canonicalTbbNumberSql . ' = ?')
        && !str_contains($overflowNumericSql['sql'], 'm.`04_nummer` = ?')
        && !str_contains($overflowNumericSql['sql'], 'm.`00_lfd` = ?')
        && $overflowNumericSql['params'][0] === $overflowNumeric
        && count($overflowNumericSql['params']) === 2,
    'Overflow-sized numeric search lost its canonical TTB bound predicate'
);
foreach (
    ['Brand+Süd', '-Brand', 'Brand*', '"Brand Süd"']
    as $reservedSearch
) {
    $fallbackSql = estab_message_list_filter_sql(array_replace($defaults, [
        'q' => $reservedSearch,
    ]));
    $assert(
        !str_contains($fallbackSql['sql'], 'MATCH(')
            && substr_count($fallbackSql['sql'], "LIKE ? ESCAPE '!'") === 7
            && count(array_unique($fallbackSql['params'])) === 1,
        'Reserved or short Boolean token reached FULLTEXT query construction'
    );
}
$mixedTokenSql = estab_message_list_filter_sql(array_replace($defaults, [
    'q' => 'S1 Lage',
]));
$assert(
    str_contains($mixedTokenSql['sql'], 'MATCH(')
        && str_contains($mixedTokenSql['sql'], ' AND ')
        && substr_count(
            $mixedTokenSql['sql'],
            "LIKE ? ESCAPE '!'"
        ) === 7
        && $mixedTokenSql['params'][0] === '+Lage*'
        && array_slice($mixedTokenSql['params'], 1)
            === array_fill(0, 7, '%S1%'),
    'Mixed short and indexed terms were not searched token by token'
);
$emptySql = estab_message_list_filter_sql($defaults);
$assert(
    $emptySql === ['sql' => '', 'params' => []],
    'Default filters emitted an active SQL condition'
);
$statusMap = [
    'draft' => 0,
    'ldf' => 1,
    'transport' => 2,
    'review' => 4,
    'done' => 8,
    'returned' => 10,
];
foreach ($statusMap as $status => $databaseValue) {
    $statusSql = estab_message_list_filter_sql(array_replace($defaults, [
        'status' => $status,
    ]));
    $assert(
        $statusSql['sql'] === 'm.`x00_status` = ?'
            && $statusSql['params'] === [$databaseValue],
        'Status filter does not map canonically: ' . $status
    );
}
$maximumDateSql = estab_message_list_filter_sql(array_replace($defaults, [
    'to' => '9999-12-31',
]));
$assert(
    $maximumDateSql === [
        'sql' => 'm.`12_abfzeit` <= ?',
        'params' => ['9999-12-31 23:59:59'],
    ],
    'Maximum database date overflowed while building the inclusive upper bound'
);
try {
    estab_message_list_filter_sql(array_replace($defaults, [
        'recipient' => ['S1'],
    ]));
    $assert(false, 'Non-string canonical recipient reached SQL construction');
} catch (InvalidArgumentException) {
    $assert(true, 'Non-string canonical recipient was rejected before SQL');
}
foreach (['m.x', 'm`', '', '1m'] as $alias) {
    try {
        estab_message_list_filter_sql($defaults, $alias);
        $assert(false, 'Unsafe SQL alias was accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'Unsafe SQL alias was rejected');
    }
}

$orders = [];
foreach (
    ['priority_newest', 'newest', 'oldest', 'number_desc', 'number_asc']
    as $sort
) {
    $orders[$sort] = estab_message_list_order_sql(['sort' => $sort], 'n');
    $assert(
        str_contains($orders[$sort], 'n.`00_lfd`')
            && preg_match('/n\.`00_lfd` (?:ASC|DESC)\z/D', $orders[$sort]) === 1,
        'Sort lacks the stable record-ID tie-breaker: ' . $sort
    );
}
$assert(
    str_contains($orders['priority_newest'], 'CASE BINARY n.`09_vorrangstufe`')
        && str_contains($orders['newest'], 'n.`12_abfzeit` DESC')
        && str_contains($orders['oldest'], 'n.`12_abfzeit` IS NULL ASC')
        && str_contains(
            $orders['number_desc'],
            'COALESCE(' . estab_message_list_tbb_number_sql('n') . ', 0) DESC'
        )
        && str_contains(
            $orders['number_asc'],
            'COALESCE(' . estab_message_list_tbb_number_sql('n')
                . ', 4294967296) ASC'
        )
        && !str_contains($orders['number_desc'], 'n.`04_nummer`')
        && !str_contains($orders['number_asc'], 'n.`04_nummer`'),
    'Sort expressions no longer implement their labels'
);
foreach ([null, '', 'drop'] as $sort) {
    try {
        estab_message_list_order_sql(['sort' => $sort]);
        $assert(false, 'Invalid sort was accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'Invalid sort was rejected');
    }
}

$firstWindow = estab_message_list_page_window(101, [
    'page' => 1,
    'page_size' => 25,
]);
$assert(
    $firstWindow === [
        'count' => 101,
        'page' => 1,
        'page_size' => 25,
        'page_count' => 5,
        'offset' => 0,
        'first' => 1,
        'last' => 25,
        'has_previous' => false,
        'has_next' => true,
    ],
    'First page window is incorrect'
);
$lastWindow = estab_message_list_page_window(101, [
    'page' => 99,
    'page_size' => 25,
]);
$assert(
    $lastWindow['page'] === 5
        && $lastWindow['offset'] === 100
        && $lastWindow['first'] === 101
        && $lastWindow['last'] === 101
        && $lastWindow['has_previous'] === true
        && $lastWindow['has_next'] === false,
    'Out-of-range page was not clamped to the last result'
);
$emptyWindow = estab_message_list_page_window(0, [
    'page' => 7,
    'page_size' => 100,
]);
$assert(
    $emptyWindow['page'] === 1
        && $emptyWindow['page_count'] === 0
        && $emptyWindow['offset'] === 0
        && $emptyWindow['first'] === 0
        && $emptyWindow['last'] === 0
        && !$emptyWindow['has_previous']
        && !$emptyWindow['has_next'],
    'Empty result window exposed a fictitious result range'
);
foreach (
    [
        [-1, ['page' => 1, 'page_size' => 25]],
        [1, ['page' => 0, 'page_size' => 25]],
        [1, ['page' => 1, 'page_size' => 15]],
    ] as [$count, $filters]
) {
    try {
        estab_message_list_page_window($count, $filters);
        $assert(false, 'Invalid page window was accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'Invalid page window was rejected');
    }
}

echo 'Message-list filter security test: OK (' . $assertions
    . " assertions)\n";
