<?php

declare(strict_types=1);

/**
 * Dwell time, overdue verdict and the unread/done filter of the message lists.
 *
 * A message list that only prints the composing time cannot tell an operator
 * which form has been lying at its station for too long, and a list without
 * "unread"/"done" cannot tell which form still needs them at all. This test
 * holds the three additions to the same rules the existing filters follow:
 * every request value comes from a fixed set, every identifier is validated
 * before it reaches SQL, the deadline table exists exactly once, and every
 * verdict is readable as a word instead of as a colour.
 */

$root = dirname(__DIR__, 2);
$originalWorkingDirectory = getcwd();
if (!is_string($originalWorkingDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
} finally {
    chdir($originalWorkingDirectory);
}
require_once $root . '/app/message_list.php';
require_once $root . '/app/message_list_ui.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$render = static function (callable $callback): string {
    ob_start();
    try {
        $callback();
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
};

// ---------------------------------------------------------------------------
// 1. The deadlines live in one table and follow the priority.
// ---------------------------------------------------------------------------
$defaultThresholds = estab_message_list_dwell_default_thresholds();
$assert(
    array_keys($defaultThresholds) === ['aaa', 'bbb', 'sss', 'eee', ''],
    'The dwell table does not cover every stored priority value'
);
$previousOverdue = 0;
foreach (['aaa', 'bbb', 'sss', 'eee'] as $priority) {
    $limits = $defaultThresholds[$priority];
    $assert(
        $limits['warn'] > 0
            && $limits['warn'] <= $limits['overdue']
            && $limits['overdue'] > $previousOverdue,
        'Priority ' . $priority . ' does not tolerate less waiting than the'
            . ' next lower one'
    );
    $previousOverdue = $limits['overdue'];
}
$assert(
    $defaultThresholds[''] === $defaultThresholds['eee'],
    'The historic and the current "no priority" value have different deadlines'
);

// The configuration may move a deadline, but only within validated bounds.
$configured = estab_message_list_dwell_thresholds([
    'sss' => ['warn' => 900, 'overdue' => '1800'],
]);
$assert(
    $configured['sss'] === ['warn' => 900, 'overdue' => 1800]
        && $configured['aaa'] === $defaultThresholds['aaa'],
    'A configured deadline was not applied, or it changed another priority'
);
foreach ([
    ['warn' => 900],
    ['warn' => 900, 'overdue' => 300],
    ['warn' => 0, 'overdue' => 600],
    ['warn' => 59, 'overdue' => 600],
    ['warn' => 600, 'overdue' => 2592001],
    ['warn' => '600abc', 'overdue' => 900],
    ['warn' => 6.0, 'overdue' => 900],
    ['warn' => true, 'overdue' => 900],
    'nonsense',
] as $broken) {
    $assert(
        estab_message_list_dwell_thresholds(['bbb' => $broken])['bbb']
            === $defaultThresholds['bbb'],
        'An unusable configured deadline did not fall back to the default'
    );
}
$assert(
    estab_message_list_dwell_thresholds('nonsense') === $defaultThresholds
        && estab_message_list_dwell_thresholds([]) === $defaultThresholds,
    'A malformed configuration silently disabled the overdue warning'
);

// ---------------------------------------------------------------------------
// 2. The verdict switches exactly at the deadline and stays honest.
// ---------------------------------------------------------------------------
$blitz = $defaultThresholds['bbb'];
$assert(
    estab_message_list_dwell_state('bbb', $blitz['warn'] - 1) === 'ok'
        && estab_message_list_dwell_state('bbb', $blitz['warn']) === 'warn'
        && estab_message_list_dwell_state('bbb', $blitz['overdue'] - 1) === 'warn'
        && estab_message_list_dwell_state('bbb', $blitz['overdue']) === 'overdue',
    'The overdue verdict does not switch at its own deadline'
);
$routine = $defaultThresholds[''];
$assert(
    estab_message_list_dwell_state('bbb', $routine['warn']) === 'overdue'
        && estab_message_list_dwell_state('', $routine['warn']) === 'warn',
    'Blitz traffic is judged by the routine deadline'
);
$assert(
    estab_message_list_dwell_state('bogus', $routine['overdue']) === 'overdue',
    'Malformed priority data bought itself a longer deadline'
);
$assert(
    estab_message_list_dwell_state('aaa', 999999, 8) === 'closed'
        && estab_message_list_dwell_state('aaa', 999999, '8') === 'closed',
    'A completed message still accumulates dwell pressure'
);
foreach ([null, '', '-5', -5, '1.5', ' 30', '0x10', ['30'], true] as $unusable) {
    $assert(
        estab_message_list_dwell_state('aaa', $unusable) === 'unknown',
        'An unusable dwell value was turned into a punctual row'
    );
}
$assert(
    estab_message_list_dwell_state('aaa', '0') === 'ok'
        && estab_message_list_dwell_seconds('0') === 0
        && estab_message_list_dwell_seconds('007') === null,
    'The database dwell value is not parsed without numeric coercion'
);

// ---------------------------------------------------------------------------
// 3. The dwell time is measured against database time on both ends.
// ---------------------------------------------------------------------------
$dwellSql = estab_message_list_dwell_select_sql('msg');
$assert(
    str_contains($dwellSql, 'nv_nachrichten_nachweiskopf')
        && str_contains($dwellSql, 'TIMESTAMPDIFF(SECOND')
        && str_contains($dwellSql, 'NOW(6)')
        && str_contains($dwellSql, 'estab_dwell_head.`updated_at`')
        && str_contains($dwellSql, 'msg.`00_lfd`')
        && str_contains($dwellSql, 'msg.`einsatz_id`')
        && str_contains($dwellSql, 'AS `estab_dwell_seconds`')
        && !str_contains($dwellSql, '?'),
    'The dwell time is not derived from the database-owned evidence head'
);
foreach (['m.x', 'm`', '', '1m', 'm;DROP'] as $alias) {
    try {
        estab_message_list_dwell_select_sql($alias);
        $assert(false, 'An unsafe alias reached the dwell subquery');
    } catch (InvalidArgumentException) {
        $assert(true, 'An unsafe alias was rejected before the dwell subquery');
    }
}

// ---------------------------------------------------------------------------
// 4. Unread and done are filters with the same safety as the existing ones.
// ---------------------------------------------------------------------------
$defaults = estab_message_list_default_filters();
$assert(
    array_key_exists('read_state', $defaults)
        && array_key_exists('done_state', $defaults)
        && $defaults['read_state'] === ''
        && $defaults['done_state'] === '',
    'The list does not know unread and done as filters'
);
foreach ([
    ['ml_read_state' => 'erledigt'],
    ['ml_read_state' => 'open'],
    ['ml_read_state' => 1],
    ['ml_done_state' => 'read'],
    ['ml_done_state' => ['done']],
    ['ml_done_state' => "done\n"],
] as $request) {
    try {
        estab_message_list_parse_filters($request, []);
        $assert(false, 'A malformed state filter was accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'A malformed state filter was rejected');
    }
}

$stateTables = ['read' => 'usr_s3_ab_read', 'done' => 'usr__fkt_s3_erl'];
$unreadSql = estab_message_list_filter_sql(
    array_replace($defaults, ['read_state' => 'unread']),
    'm',
    $stateTables
);
$doneSql = estab_message_list_filter_sql(
    array_replace($defaults, ['done_state' => 'done']),
    'm',
    $stateTables
);
$assert(
    $unreadSql['params'] === [] && $doneSql['params'] === [],
    'A state filter smuggled a request value into the parameter list'
);
$assert(
    $unreadSql['sql'] === 'NOT EXISTS (SELECT 1 FROM `usr_s3_ab_read` AS'
        . ' estab_read_state WHERE estab_read_state.`nachnum` = m.`00_lfd`)',
    'The unread filter is not an exact NOT EXISTS on the read table'
);
$assert(
    $doneSql['sql'] === 'EXISTS (SELECT 1 FROM `usr__fkt_s3_erl` AS'
        . ' estab_done_state WHERE estab_done_state.`nachnum` = m.`00_lfd`)',
    'The done filter is not an exact EXISTS on the done table'
);
$combinedSql = estab_message_list_filter_sql(
    array_replace($defaults, [
        'read_state' => 'read',
        'done_state' => 'open',
        'q' => '100%_!',
    ]),
    'm',
    $stateTables
);
$assert(
    substr_count($combinedSql['sql'], 'EXISTS (SELECT 1 FROM') === 2
        && str_contains($combinedSql['sql'], 'NOT EXISTS (SELECT 1 FROM')
        && substr_count($combinedSql['sql'], "LIKE ? ESCAPE '!'") === 7
        && in_array('%100!%!_!!%', $combinedSql['params'], true),
    'Combining a state filter with a search broke the LIKE escaping'
);

// A table name is an identifier, never request data.
foreach ([
    ['read' => 'usr_s3_ab_read`; DROP TABLE nv_nachrichten; --'],
    ['read' => 'usr s3'],
    ['read' => '1usr'],
    ['read' => ''],
    ['read' => str_repeat('a', 65)],
    ['read' => ['usr_s3_ab_read']],
    [],
] as $unsafe) {
    try {
        estab_message_list_filter_sql(
            array_replace($defaults, ['read_state' => 'unread']),
            'm',
            $unsafe
        );
        $assert(false, 'An unsafe state table reached the filter SQL');
    } catch (InvalidArgumentException) {
        $assert(true, 'An unsafe state table was rejected before the SQL');
    }
}
$assert(
    estab_message_list_has_state_tables($stateTables) === true
        && estab_message_list_has_state_tables([]) === false
        && estab_message_list_has_state_tables(['read' => 'usr_x_read'])
            === false,
    'A caller without both state tables was treated as if it had them'
);
$assert(
    estab_message_list_state_select_sql([]) === ''
        && str_contains(
            estab_message_list_state_select_sql($stateTables, 'm'),
            'AS `estab_state_read`'
        )
        && str_contains(
            estab_message_list_state_select_sql($stateTables, 'm'),
            'AS `estab_state_done`'
        ),
    'The state columns are not bound to the availability of both tables'
);

// Paging and the stable order must not change because of the new filters.
$stateFiltered = array_replace($defaults, [
    'read_state' => 'unread',
    'page' => 3,
    'page_size' => 25,
]);
$window = estab_message_list_page_window(76, $stateFiltered);
$assert(
    $window['offset'] === 50 && $window['page_count'] === 4,
    'The page window changed with the new filters'
);
$assert(
    estab_message_list_apply_request(
        $stateFiltered,
        ['ml_read_state' => 'read'],
        []
    )['page'] === 1,
    'Changing the unread filter kept a stale page'
);
$assert(
    str_ends_with(
        estab_message_list_order_sql($stateFiltered, 'm'),
        'm.`00_lfd` DESC'
    ),
    'The stable tie-breaker of the order disappeared'
);

// ---------------------------------------------------------------------------
// 5. Every row says its dwell time and its reading state in words.
// ---------------------------------------------------------------------------
$overdueSeconds = $defaultThresholds['bbb']['overdue'] + 60;
$rows = [
    [
        '00_lfd' => 41,
        '04_richtung' => 'E',
        'estab_tbb_book_lfd' => 142,
        '05_gegenstelle' => 'Florian Nord',
        '09_vorrangstufe' => 'bbb',
        '10_anschrift' => 'S3',
        '12_betreff' => 'Lagemeldung',
        '12_inhalt' => 'Inhalt',
        '12_abfzeit' => '2026-07-31 12:36:00',
        '13_abseinheit' => 'Abschnitt Nord',
        '14_funktion' => 'S3',
        '16_empf' => 'S2_rt,',
        'x00_status' => 4,
        'estab_dwell_seconds' => (string) $overdueSeconds,
        'estab_state_read' => 0,
        'estab_state_done' => 0,
    ],
    [
        '00_lfd' => 42,
        '04_richtung' => 'A',
        'estab_tbb_book_lfd' => 143,
        '05_gegenstelle' => 'Florian Süd',
        '09_vorrangstufe' => '',
        '10_anschrift' => 'S2',
        '12_betreff' => 'Anforderung',
        '12_inhalt' => 'Inhalt',
        '12_abfzeit' => '2026-07-31 12:40:00',
        '13_abseinheit' => 'Abschnitt Süd',
        '14_funktion' => 'S4',
        '16_empf' => 'S2_rt,',
        'x00_status' => 8,
        'estab_dwell_seconds' => '99999',
        'estab_state_read' => 1,
        'estab_state_done' => '1',
    ],
    [
        '00_lfd' => 43,
        '04_richtung' => 'E',
        'estab_tbb_book_lfd' => 144,
        '05_gegenstelle' => 'Florian West',
        '09_vorrangstufe' => 'sss',
        '10_anschrift' => 'S1',
        '12_betreff' => 'Nachforderung',
        '12_inhalt' => 'Inhalt',
        '12_abfzeit' => null,
        '13_abseinheit' => 'Abschnitt West',
        '14_funktion' => 'S1',
        '16_empf' => '',
        'x00_status' => 1,
    ],
];
$table = $render(static function () use ($rows): void {
    estab_message_list_render_table($rows, static function (array $row): void {
        echo '<a class="estab-button estab-message-list-open" href="/detail">'
            . 'Vordruck öffnen</a>';
    });
});
$assert(
    substr_count($table, '<th scope="col"') === 8
        && str_contains($table, '>Zeit und Verweildauer</th>')
        && str_contains($table, '>Kenntnis</th>'),
    'The list has no dwell or reading-state column'
);
$assert(
    str_contains($table, 'data-estab-message-dwell="overdue"')
        && str_contains(
            $table,
            'data-estab-message-dwell-seconds="' . $overdueSeconds . '"'
        )
        && str_contains($table, 'überfällig')
        && str_contains($table, 'data-estab-message-dwell="closed"')
        && str_contains($table, 'data-estab-message-dwell="unknown"')
        && str_contains($table, 'Verweildauer nicht nachgewiesen'),
    'The dwell verdict is missing, or it is not readable as text'
);
$assert(
    substr_count($table, 'estab-message-list-dwell--overdue') === 1
        && substr_count($table, 'class="estab-message-list-awareness-group"')
            === 3
        && str_contains($table, '>ungelesen</span>')
        && str_contains($table, '>gelesen</span>')
        && str_contains($table, '>offen</span>')
        && str_contains($table, '>erledigt</span>')
        && str_contains($table, '>nicht geführt</span>'),
    'A row does not state its reading and handling state in words'
);
// Colour must never be the only carrier: strip every class attribute and the
// verdicts have to survive as plain text.
$withoutClasses = (string) preg_replace('~\sclass="[^"]*"~', '', $table);
$assert(
    str_contains($withoutClasses, 'überfällig')
        && str_contains($withoutClasses, 'ungelesen')
        && str_contains($withoutClasses, 'erledigt'),
    'The list encodes overdue or unread traffic in colour alone'
);
$assert(
    substr_count($table, 'title="Vorrang Blitz: überfällig ab ') === 1,
    'The row does not name the deadline it is measured against'
);

// ---------------------------------------------------------------------------
// 6. The Nachweisung reads one page and shortens its content.
// ---------------------------------------------------------------------------
$long = str_repeat('Lagemeldung Abschnitt Nord ', 40);
$clamped = estab_message_list_clamped_text($long . '<script>x</script>');
$assert(
    !str_contains($clamped, '<script>')
        && str_contains($clamped, '&lt;script&gt;')
        && str_contains($clamped, '<details class="estab-message-list-fulltext">')
        && str_contains($clamped, 'Ganze Nachricht')
        && substr_count($clamped, 'Lagemeldung') > 1,
    'The shortened content hides the full message or renders data as markup'
);
$assert(
    !str_contains(estab_message_list_clamped_text('Kurz'), '<details')
        && str_contains(
            estab_message_list_clamped_text(''),
            'estab-message-list-clamp--empty'
        ),
    'A short or empty content grew a pointless disclosure control'
);

$listSource = file_get_contents($root . '/4fach/liste.php');
if (!is_string($listSource)) {
    throw new RuntimeException('Could not read 4fach/liste.php');
}
$section = static function (string $source, string $start, string $end): string {
    $from = strrpos($source, $start);
    $to = $from === false ? false : strpos($source, $end, $from);
    if ($from === false || $to === false) {
        throw new RuntimeException('Could not isolate ' . $start);
    }
    return substr($source, $from, $to - $from);
};
$incoming = $section($listSource, 'case "FmNwE":', 'case "FmNwA":');
$outgoing = $section($listSource, 'case "FmNwA":', 'case "FmNw":');
$combined = $section($listSource, 'case "FmNw":', '} // switch');
foreach ([
    'Nachweisung Eingang' => [$incoming, 'nwe_'],
    'Nachweisung Ausgang' => [$outgoing, 'nwa_'],
    'Nachweisung Eingang/Ausgang' => [$combined, 'nw_'],
] as $surface => [$case, $prefix]) {
    $assert(
        str_contains($case, 'estab_list_tracking_filters ("' . $prefix . '")')
            && str_contains(
                $case,
                'estab_list_tracking_pager ($trackingFilters, $trackingWindow, "'
                    . $prefix . '")'
            )
            && str_contains(
                $case,
                'estab_message_list_render_resultbar ($trackingFilters'
            ),
        $surface . ' has no page control of its own'
    );
    $assert(
        str_contains($case, 'estab_message_list_clamped_text ($row["12_inhalt"]')
            && !str_contains(
                $case,
                'echo "<a>".estab_message_html ($row["12_inhalt"])'
            ),
        $surface . ' still prints the complete message text in every row'
    );
}
$assert(
    str_contains($incoming, 'LIMIT ? OFFSET ?')
        && str_contains($outgoing, 'LIMIT ? OFFSET ?')
        && substr_count($incoming, 'SELECT COUNT(*)') === 1
        && substr_count($outgoing, 'SELECT COUNT(*)') === 1,
    'A separate Nachweisung still reads every row of the incident'
);
$combinedRows = $section(
    $listSource,
    'function estab_list_combined_tracking_rows (',
    'function estab_list_combined_tracking_data ('
);
$assert(
    str_contains($combinedRows, 'LIMIT ? OFFSET ?')
        && str_contains($combinedRows, 'array ($incidentId, $limit, $offset)')
        && str_contains(
            $listSource,
            'function estab_list_combined_tracking_count ('
        ),
    'The combined transmission log still reads every row of the incident'
);

// The second sighting must offer the new filter only where it can answer it.
$adminList = $section(
    $listSource,
    'function get_admin_message_list (',
    'function get_list ('
);
$assert(
    str_contains($adminList, '$this->message_list_state_tables ($identity)')
        && str_contains(
            $adminList,
            'estab_message_list_has_state_tables ($stateTables)'
        )
        && str_contains($adminList, '$this->filters ["read_state"] = "";')
        && str_contains($adminList, '$this->filters ["done_state"] = "";')
        && str_contains(
            $adminList,
            'estab_message_list_filter_sql (
      $this->filters,
      "m",
      $stateTables
    )'
        )
        && str_contains($adminList, 'estab_message_list_dwell_select_sql ("m")'),
    'The second sighting does not bind the state filter to its own identity'
);
$assert(
    str_contains(
        $listSource,
        '"state_filters" => $this->stateFilterAvailable === true,'
    ),
    'The state filter is offered without proving that it can be answered'
);
$controls = $render(static function (): void {
    estab_message_list_render_controls(
        estab_message_list_default_filters(),
        ['S2'],
        ['action' => '/4fach/mainindex.php', 'dom_prefix' => 'x-list']
    );
});
$statefulControls = $render(static function (): void {
    estab_message_list_render_controls(
        estab_message_list_default_filters(),
        ['S2'],
        [
            'action' => '/4fach/mainindex.php',
            'dom_prefix' => 'y-list',
            'state_filters' => true,
        ]
    );
});
$assert(
    !str_contains($controls, 'name="ml_read_state"')
        && !str_contains($controls, 'name="ml_done_state"')
        && str_contains($statefulControls, 'name="ml_read_state"')
        && str_contains($statefulControls, 'name="ml_done_state"')
        && str_contains($statefulControls, 'for="y-list-read-state"')
        && str_contains($statefulControls, 'id="y-list-read-state"'),
    'The state filter is not bound to the availability its caller declares'
);

// Two Nachweisungen on one page must page independently.
$pagerFilters = array_replace($defaults, ['page' => 2, 'sort' => 'number_asc']);
$pagerWindow = estab_message_list_page_window(300, $pagerFilters);
$pager = $render(static function () use ($pagerFilters, $pagerWindow): void {
    estab_message_list_render_pager($pagerFilters, $pagerWindow, [
        'action' => 'nachwea.php',
        'method' => 'get',
        'prefix' => 'nwe_',
        'hidden' => ['nwalle' => '1'],
    ]);
});
$assert(
    substr_count($pager, 'name="nwe_page"') === 4
        && !str_contains($pager, 'name="ml_page"')
        && str_contains($pager, 'name="nwe_page_size"')
        && str_contains($pager, 'name="nwalle"'),
    'The Nachweisung page control does not use its own request namespace'
);
foreach (['', '1nwe_', 'nwe-', str_repeat('n', 33)] as $badPrefix) {
    try {
        estab_message_list_render_pager($pagerFilters, $pagerWindow, [
            'action' => 'nachwea.php',
            'prefix' => $badPrefix,
        ]);
        $assert(false, 'A malformed pager namespace was accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'A malformed pager namespace was rejected');
    }
}

// ---------------------------------------------------------------------------
// 7. The extra column must not make the table wider on a 1366x768 laptop.
// ---------------------------------------------------------------------------
$stylesheet = file_get_contents($root . '/estab-ui.css');
if (!is_string($stylesheet)) {
    throw new RuntimeException('Could not read estab-ui.css');
}
$assert(
    preg_match(
        '~\.estab-message-list-table\s*\{[^}]*min-width:\s*68rem~',
        $stylesheet
    ) === 1,
    'The result table no longer keeps its 68rem minimum width'
);
preg_match_all(
    '~\.estab-message-list-table thead th:nth-child\((\d)\)\s*\{\s*width:\s*(\d+)%~',
    $stylesheet,
    $columnMatches,
    PREG_SET_ORDER
);
$assert(
    count($columnMatches) === 8
        && array_sum(array_column($columnMatches, 2)) === 100,
    'The eight columns do not share exactly one table width'
);
foreach ([
    'estab-message-list-dwell--overdue',
    'estab-message-list-dwell--warn',
    'estab-message-list-awareness--unread',
    'estab-message-list-awareness--done',
] as $class) {
    $assert(
        str_contains($stylesheet, '.' . $class . ' {')
            || str_contains($stylesheet, '.' . $class . ',')
            || str_contains($stylesheet, '.' . $class . '::before'),
        'The stylesheet gives ' . $class . ' no meaning'
    );
}

printf(
    "message-list dwell and state security: OK (%d assertions)\n",
    $assertions
);
