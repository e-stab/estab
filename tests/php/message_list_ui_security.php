<?php

declare(strict_types=1);

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
require_once __DIR__ . '/../../app/message_list.php';
require_once __DIR__ . '/../../app/message_list_ui.php';
require_once __DIR__ . '/lib/quelltext.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Capture a real renderer without allowing a failed callback to leak output. */
$render = static function (callable $callback): string {
    ob_start();
    try {
        $callback();
        $html = ob_get_contents();
        if (!is_string($html)) {
            throw new RuntimeException('Message-list renderer output is unavailable');
        }
        return $html;
    } finally {
        ob_end_clean();
    }
};

/** @return list<string> */
$htmlIds = static function (string $html): array {
    preg_match_all('/\bid="([^"]+)"/', $html, $matches);
    return array_values(array_map(
        static fn (string $id): string => html_entity_decode(
            $id,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ),
        $matches[1] ?? []
    ));
};

/** Assert every explicit label target exists exactly once in the fragment. */
$assertLabelTargets = static function (
    string $html,
    string $surface
) use ($assert, $htmlIds): void {
    $ids = $htmlIds($html);
    $counts = array_count_values($ids);
    $assert(
        $ids !== [] && count($counts) === count($ids),
        $surface . ' contains missing or duplicate control IDs'
    );
    preg_match_all('/<label\b[^>]*\bfor="([^"]+)"/i', $html, $matches);
    $targets = $matches[1] ?? [];
    $assert($targets !== [], $surface . ' has no explicit control labels');
    foreach ($targets as $encodedTarget) {
        $target = html_entity_decode(
            (string) $encodedTarget,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $assert(
            ($counts[$target] ?? 0) === 1,
            $surface . ' label target is missing or ambiguous: ' . $target
        );
    }
};

/** Assert one and only one actionable open control in every result row. */
$assertOneOpenPerRow = static function (
    string $html,
    int $expectedRows,
    string $surface
) use ($assert): void {
    preg_match_all(
        '/<tr\b[^>]*class="[^"]*\bestab-message-list-row\b[^"]*"[^>]*>'
            . '(.*?)<\/tr>/is',
        $html,
        $rows
    );
    $assert(
        count($rows[1] ?? []) === $expectedRows,
        $surface . ' rendered an unexpected number of result rows'
    );
    foreach ($rows[1] ?? [] as $index => $rowHtml) {
        preg_match_all(
            '/<(?:a|button)\b[^>]*class="[^"]*\bestab-message-list-open\b[^"]*"/i',
            (string) $rowHtml,
            $actions
        );
        $assert(
            count($actions[0] ?? []) === 1,
            $surface . ' row ' . ($index + 1)
                . ' does not expose exactly one open action'
        );
    }
};

$filters = array_replace(estab_message_list_default_filters(), [
    'q' => 'Lage <script>alert("query")</script> & Süd',
    'direction' => 'E',
    'priority' => 'bbb',
    'status' => 'done',
    'from' => '2026-07-01',
    'to' => '2026-07-31',
    'recipient' => 'S2',
    'sort' => 'oldest',
    'page' => 2,
    'page_size' => 25,
]);
$recipients = ['S1', 'S2', 'A/W', 'POL', 'THW <script>alert("recipient")</script>'];

$overviewControls = $render(static function () use ($filters, $recipients): void {
    estab_message_list_render_controls($filters, $recipients, [
        'action' => '/4fueltg/ue_ltg.php?from="overview"&x=<script>',
        'method' => 'get',
        'target' => '_self',
        'dom_prefix' => 'overview-message-list',
        'hidden' => ['overview_scope' => 'active&incident'],
    ]);
});
$secondSightingControls = $render(
    static function () use ($filters, $recipients): void {
        estab_message_list_render_controls($filters, $recipients, [
            'action' => '/4fach/mainindex.php',
            'method' => 'post',
            'target' => '_self',
            'dom_prefix' => 'second-sighting-message-list',
            'hidden' => ['second_sighting' => 'si'],
            'csrf_html' => '<input type="hidden" name="csrf_token" '
                . 'value="trusted-test-token">',
        ]);
    }
);

foreach ([
    'Meldungsübersicht' => $overviewControls,
    'Zweite Sichtung' => $secondSightingControls,
] as $surface => $controls) {
    $assert(
        str_contains($controls, 'value="A/W">Fernmelder</option>')
            && !str_contains($controls, '>A/W</option>'),
        $surface . ' exposes the persisted recipient function key'
    );
    $assert(
        substr_count($controls, 'data-estab-message-list-controls') === 1
            && substr_count($controls, 'class="estab-message-list-search-form"')
                === 1
            && substr_count($controls, 'role="search"') === 1,
        $surface . ' does not have one shared search surface'
    );
    $assert(
        preg_match(
            '/<div class="estab-message-list-search-row">.*?'
                . '<input\b[^>]*type="search"[^>]*name="ml_q"[^>]*>'
                . '.*?<button\b[^>]*>Suchen<\/button>/s',
            $controls
        ) === 1,
        $surface . ' search is hidden or lacks its primary submit action'
    );
    $assert(
        str_contains($controls, 'maxlength="120"')
            && str_contains($controls, 'enterkeyhint="search"')
            && str_contains($controls, 'aria-describedby="')
            && str_contains(
                $controls,
                'Durchsucht Vordruck-Überschrift (Betreff), '
                    . 'TBB-Nachweisnummer, Rufname, Rufnummer, Von, An, '
                    . 'Verfasserfunktion und Nachrichtentext.'
            ),
        $surface . ' search label or scope help is incomplete'
    );
    $assert(
        str_contains(
            $controls,
            '<fieldset class="estab-message-list-quick-filters">'
        )
            && str_contains($controls, '<legend>Schnellfilter</legend>')
            && str_contains($controls, 'name="ml_direction"')
            && str_contains($controls, 'name="ml_priority"')
            && str_contains($controls, 'name="ml_status"'),
        $surface . ' lacks grouped, labelled quick filters'
    );
    $assert(
        str_contains($controls, '<details class="estab-message-list-more" open>')
            && str_contains($controls, 'Weitere Filter und Sortierung · aktiv')
            && str_contains($controls, 'class="estab-message-list-filter-grid"')
            && str_contains($controls, 'name="ml_from"')
            && str_contains($controls, 'name="ml_to"')
            && str_contains($controls, 'name="ml_recipient"')
            && str_contains($controls, 'name="ml_sort"')
            && str_contains($controls, 'name="ml_page_size"'),
        $surface . ' lacks the discoverable advanced filters'
    );
    $assert(
        substr_count($controls, 'class="estab-message-list-chip"') === 7
            && substr_count($controls, 'name="ml_remove"') === 7
            && substr_count($controls, ' entfernen"') === 7
            && str_contains($controls, 'name="ml_reset"')
            && str_contains($controls, 'Alle Filter zurücksetzen'),
        $surface . ' does not expose removable active filters and reset'
    );
    $assert(
        strpos($controls, 'estab-message-list-search-row')
            < strpos($controls, 'estab-message-list-more'),
        $surface . ' hides the primary search inside advanced filters'
    );
    $assert(
        substr_count($controls, '<form') === 1
            && substr_count($controls, '</form>') === 1,
        $surface . ' controls contain nested or incomplete forms'
    );
    $assertLabelTargets($controls, $surface);
    $assert(
        !str_contains($controls, '<script>')
            && !str_contains($controls, '<img')
            && !str_contains($controls, 'type="image"')
            && !str_contains($controls, 'button.php')
            && !preg_match('/<meta\b[^>]*http-equiv="refresh"/i', $controls)
            && str_contains($controls, '&lt;script&gt;'),
        $surface . ' controls execute data or retain image/refresh UI'
    );
}
$assert(
    estab_message_list_filter_labels(['recipient' => 'A/W'])['recipient']
        === 'Empfänger: Fernmelder',
    'active recipient filter exposes the persisted function key'
);

$assert(
    str_contains($overviewControls, 'method="get"')
        && str_contains($overviewControls, 'target="_self"')
        && str_contains(
            $overviewControls,
            'action="/4fueltg/ue_ltg.php?from=&quot;overview&quot;&amp;x='
                . '&lt;script&gt;"'
        )
        && str_contains($overviewControls, 'value="active&amp;incident"'),
    'Overview controls did not retain their inert GET route'
);
$assert(
    str_contains($secondSightingControls, 'method="post"')
        && str_contains($secondSightingControls, 'target="_self"')
        && substr_count($secondSightingControls, 'name="csrf_token"') === 1
        && str_contains($secondSightingControls, 'name="second_sighting"'),
    'Second-sighting controls lost POST, CSRF or route state'
);

$pageWindow = estab_message_list_page_window(76, $filters);
$resultbar = $render(static function () use ($filters, $pageWindow): void {
    estab_message_list_render_resultbar($filters, $pageWindow, [
        'updated_at' => '12:34 <script>alert("time")</script>',
    ]);
});
$assert(
    str_contains($resultbar, 'class="estab-message-list-resultcount"')
        && str_contains($resultbar, 'role="status"')
        && str_contains($resultbar, 'aria-live="polite"')
        && str_contains($resultbar, '<strong>26–50 von 76 Nachrichten</strong>')
        && str_contains($resultbar, 'Sortierung: Älteste zuerst'),
    'Result bar does not announce an exact range and active sort'
);
$assert(
    !str_contains($resultbar, '<script>')
        && str_contains($resultbar, '&lt;script&gt;'),
    'Result-bar update metadata became executable HTML'
);

$overviewPager = $render(static function () use ($filters, $pageWindow): void {
    estab_message_list_render_pager($filters, $pageWindow, [
        'action' => '/4fueltg/ue_ltg.php',
        'method' => 'get',
        'target' => '_self',
        'hidden' => ['scope' => 'active"><script>alert(1)</script>'],
    ]);
});
$assert(
    str_contains(
        $overviewPager,
        '<nav class="estab-message-list-pager" aria-label="Ergebnisseiten">'
    )
        && substr_count($overviewPager, 'name="ml_page"') === 4
        && str_contains(
            $overviewPager,
            '<span class="estab-message-list-page-status" '
                . 'aria-current="page">Seite 2 von 4</span>'
        )
        && str_contains($overviewPager, 'name="ml_q"')
        && str_contains($overviewPager, 'name="ml_page_size"'),
    'Pager is incomplete or does not preserve the active filter state'
);
$assert(
    !str_contains($overviewPager, '<script>')
        && str_contains($overviewPager, '&lt;script&gt;')
        && !str_contains($overviewPager, '<img')
        && !str_contains($overviewPager, 'type="image"'),
    'Pager metadata became active HTML or retained image buttons'
);

$lastPageWindow = estab_message_list_page_window(76, array_replace(
    $filters,
    ['page' => 4]
));
$lastPager = $render(static function () use ($filters, $lastPageWindow): void {
    estab_message_list_render_pager(
        array_replace($filters, ['page' => 4]),
        $lastPageWindow,
        [
            'action' => '/4fach/mainindex.php',
            'method' => 'post',
            'target' => '_self',
            'hidden' => ['second_sighting' => 'aw'],
            'csrf_html' => '<input type="hidden" name="csrf_token" value="token">',
        ]
    );
});
$assert(
    substr_count($lastPager, ' disabled') === 2
        && str_contains($lastPager, '>Seite 4 von 4</span>')
        && substr_count($lastPager, 'name="csrf_token"') === 1,
    'Last-page state does not disable forward navigation or retain CSRF'
);

$attachmentTokens = estab_message_list_attachment_tokens(
    ' EL0001.pdf;EL0001.pdf;EL0002.JPG;../secret.pdf;'
        . '<iframe src=javascript:alert(1)>.pdf;EL0003.php;'
);
$assert(
    $attachmentTokens === ['EL0001.pdf', 'EL0002.jpg']
        && estab_message_list_attachment_tokens(null) === []
        && estab_message_list_attachment_tokens(['EL0001.pdf']) === [],
    'Attachment badge parser accepts unsafe fragments, duplicates, or non-text values'
);
$assert(
    estab_message_list_attachment_label(1) === '1 Anlage'
        && estab_message_list_attachment_label(2) === '2 Anlagen',
    'Attachment badge does not use an understandable singular/plural label'
);

$longSubject = str_repeat('Ü', 255);
$rows = [
    [
        '00_lfd' => 41,
        '04_richtung' => 'E',
        '04_nummer' => 9142,
        'estab_tbb_book_lfd' => 142,
        '05_gegenstelle' => 'Florian <script>alert("station")</script>',
        '09_vorrangstufe' => 'bbb',
        '10_anschrift' => 'S2 & Lage',
        '12_anhang' => 'EL0001.pdf; EL0001.pdf;../secret.pdf;'
            . '<iframe src=javascript:alert(1)>.pdf;EL0003.php;',
        '12_betreff' => 'Gefahr </strong><script>alert("subject")</script>',
        '12_inhalt' => 'Text <img src=x onerror=alert("body")> & Ende',
        '12_abfzeit' => '2026-07-31 12:34:00',
        '13_abseinheit' => 'Abschnitt <svg onload=alert("from")>',
        '14_funktion' => 'S1',
        '16_empf' => 'S2_rt,S1_gn,A/W_bl,',
        'x00_status' => 8,
    ],
    [
        '00_lfd' => 42,
        '04_richtung' => 'A',
        '04_nummer' => 9143,
        'estab_tbb_book_lfd' => null,
        '05_gegenstelle' => 'Florian West',
        '09_vorrangstufe' => '',
        '10_anschrift' => 'Einsatzabschnitt West',
        '12_anhang' => 'EL0002.JPG;EL0003.txt;EL0002.JPG;',
        '12_betreff' => '',
        '12_inhalt' => 'Regulärer Inhalt',
        '12_abfzeit' => null,
        '13_abseinheit' => '',
        '14_funktion' => 'S3',
        '16_empf' => '',
        'x00_status' => 4,
    ],
    [
        '00_lfd' => 43,
        '04_richtung' => 'E',
        '04_nummer' => 9144,
        'estab_tbb_book_lfd' => 144,
        '05_gegenstelle' => 'Florian Süd',
        '09_vorrangstufe' => 'eee',
        '10_anschrift' => 'Einsatzabschnitt Süd',
        '12_anhang' => '',
        '12_betreff' => $longSubject,
        '12_inhalt' => 'Regulärer Inhalt ohne Anlage',
        '12_abfzeit' => '2026-07-31 12:36:00',
        '13_abseinheit' => 'Abschnitt Süd',
        '14_funktion' => 'S4',
        '16_empf' => 'S2_rt,',
        'x00_status' => 1,
    ],
];

$overviewTable = $render(static function () use ($rows): void {
    estab_message_list_render_table($rows, static function (array $row): void {
        $recordId = estab_message_positive_id($row['00_lfd'] ?? null);
        echo '<a class="estab-button estab-message-list-open" href="/detail?id='
            . $recordId . '">Vordruck ' . $recordId . ' öffnen</a>';
    });
});
$secondSightingTable = $render(static function () use ($rows): void {
    estab_message_list_render_table($rows, static function (array $row): void {
        $recordId = estab_message_positive_id($row['00_lfd'] ?? null);
        echo '<form method="post" action="/4fach/mainindex.php" target="_self">'
            . '<input type="hidden" name="csrf_token" value="token">'
            . '<input type="hidden" name="fm" value="SI-Adminmeldung">'
            . '<input type="hidden" name="00_lfd" value="' . $recordId . '">'
            . '<button class="estab-button estab-message-list-open" '
            . 'type="submit">Vordruck ' . $recordId . ' öffnen</button></form>';
    });
});

foreach ([
    'Meldungsübersicht' => $overviewTable,
    'Zweite Sichtung' => $secondSightingTable,
] as $surface => $table) {
    /*
     * Die Kopfzellen tragen seit der Umstellung auf das Tabellenbauteil
     * ihre Breite als Attribut -- `<th scope="col" style="width:12%">`.
     * Geprueft wird deshalb der Anfang, nicht die geschlossene Klammer.
     */
    $assert(
        substr_count($table, '<caption class="estab-visually-hidden">') === 1
            && substr_count($table, '<th scope="col"') === 8
            && substr_count($table, 'data-label=') === 24,
        $surface . ' result table lacks semantic or responsive labels'
    );
    $assert(
        str_contains($table, '>Zeit und Verweildauer</th>')
            && str_contains($table, '>Kenntnis</th>')
            && substr_count($table, 'data-estab-message-dwell="') === 3
            && substr_count(
                $table,
                'class="estab-message-list-awareness-group"'
            ) === 3,
        $surface . ' hides dwell time or reading state from the row'
    );
    $assert(
        str_contains($table, '>Überschrift und Inhalt</th>')
            && substr_count(
                $table,
                'data-estab-message-list-heading '
            ) === 3
            && substr_count(
                $table,
                'data-estab-message-list-heading-empty="true"'
            ) === 1
            && substr_count(
                $table,
                'data-estab-message-list-heading-empty="false"'
            ) === 2
            && str_contains($table, 'Keine Überschrift angegeben')
            && str_contains($table, $longSubject)
            && substr_count(
                $table,
                '<span class="estab-message-list-field-label">'
                    . 'Vordruck-Überschrift</span>'
            ) === 3,
        $surface . ' does not expose each complete message-form heading'
    );
    $assert(
        preg_match(
            '/data-message-id="41".*?data-estab-message-list-heading '
                . 'data-estab-message-list-heading-empty="false">'
                . 'Gefahr &lt;\/strong&gt;&lt;script&gt;alert\(&quot;subject&quot;\)'
                . '&lt;\/script&gt;<\/strong>.*?estab-message-list-excerpt/s',
            $table
        ) === 1,
        $surface . ' heading is missing, misplaced, or not safely escaped'
    );
    /*
     * Eine interne Nachrichtennummer tritt nie an die Stelle des
     * TBB-Nachweises. Fehlt der Nachweis, sagt die Liste warum: Eine
     * Gespraechsnotiz bekommt nie eine Nummer, ein Ausgang bekommt sie mit
     * der Befoerderung. Frueher hiess es fuer alle drei Faelle gleich "noch
     * kein TBB-Nachweis" -- damit sah eine Liste aus, als fehle ueberall
     * etwas, auch dort, wo nichts fehlt.
     */
    $ohneNachweis = substr_count($table, 'noch kein TBB-Nachweis')
        + substr_count($table, 'ohne TBB-Nachweis · Gesprächsnotiz')
        + substr_count($table, 'TBB-Nachweis mit der Beförderung');
    $assert(
        str_contains($table, 'TBB-Nachweis 142')
            && $ohneNachweis >= 1
            && !str_contains($table, '9142')
            && !str_contains($table, '9143')
            && !str_contains($table, '9144'),
        $surface . ' substitutes an internal message number for TBB evidence'
    );
    $assertOneOpenPerRow($table, 3, $surface);
    $assert(
        str_contains($table, 'data-priority="bbb"')
            && str_contains($table, '>Blitz</span>')
            && str_contains($table, 'data-status="8"')
            && str_contains($table, '>Abgeschlossen</span>')
            && str_contains($table, 'S1')
            && str_contains($table, 'S2')
            && str_contains($table, '>Fernmelder</span>')
            && !str_contains($table, '>A/W</span>'),
        $surface . ' relies on colour or lost recipient labels'
    );
    $assert(
        substr_count(
            $table,
            'class="estab-message-list-correspondents"'
        ) === 3
            && str_contains($table, '<strong>Von:</strong>')
            && str_contains($table, '<strong>An:</strong>'),
        $surface . ' joins sender and recipient without structure'
    );
    $assert(
        !str_contains($table, '<script>')
            && !str_contains($table, '<img src=x')
            && !str_contains($table, '<svg ')
            && str_contains($table, '&lt;script&gt;')
            && str_contains($table, '&lt;img src=x onerror=alert')
            && str_contains($table, '&lt;svg onload=alert'),
        $surface . ' result data became executable HTML'
    );
    $assert(
        substr_count($table, 'data-estab-message-attachment-badge') === 2
            && substr_count(
                $table,
                'data-estab-message-attachment-count="1" aria-label="1 Anlage">1 Anlage</span>'
            ) === 1
            && substr_count(
                $table,
                'data-estab-message-attachment-count="2" aria-label="2 Anlagen">2 Anlagen</span>'
            ) === 1
            && preg_match(
                '/data-message-id="43"[^>]*>.*?data-estab-message-attachment-badge/s',
                $table
            ) !== 1
            && !str_contains($table, '<iframe'),
        $surface . ' attachment badge is inaccurate, inaccessible, or unsafe'
    );
    $assert(
        !str_contains($table, 'estab-tool-table-responsive'),
        $surface . ' reactivates conflicting legacy mobile-table CSS'
    );
}
/*
 * Die Zeilen der Uebersicht tragen genau einen traegen Verweis und kein
 * Formular: Ein Formular in einer Zeile waere ein Bedienelement, das etwas
 * aendert, wo nur gelesen wird.
 *
 * Gezaehlt wird deshalb im Rumpf der Tabelle, nicht im ganzen Markup: Das
 * Suchband des Tabellenbauteils ist ein Formular und steht ueber der
 * Tabelle, nicht in ihr.
 */
$overviewBody = '';
if (preg_match('~<tbody>(.*)</tbody>~s', $overviewTable, $overviewRows) === 1) {
    $overviewBody = $overviewRows[1];
}
$assert(
    $overviewBody !== ''
        && substr_count($overviewBody, '<form') === 0
        && substr_count($overviewBody, '<a class="estab-button '
            . 'estab-message-list-open"') === 3,
    'Overview rows do not expose exactly one inert detail link each'
);
/*
 * Auch hier wird im Rumpf gezaehlt: Das Suchband des Tabellenbauteils ist
 * ein Formular und schliesst sich vor der Tabelle -- ein Formular in einem
 * Formular wuerfe der Browser weg, und der Knopf "Vordruck oeffnen" taete
 * dann nichts mehr. Dass es sich schliesst, wird unten eigens geprueft.
 */
$secondSightingBody = '';
if (preg_match('~<tbody>(.*)</tbody>~s', $secondSightingTable, $secondRows) === 1) {
    $secondSightingBody = $secondRows[1];
}
$assert(
    $secondSightingBody !== ''
        && substr_count($secondSightingBody, '<form') === 3
        && substr_count($secondSightingBody, 'name="csrf_token"') === 3
        && substr_count($secondSightingBody, 'name="00_lfd"') === 3
        && substr_count($secondSightingBody, 'name="fm"') === 3,
    'Second-sighting rows do not expose one CSRF-protected POST action each'
);
/*
 * Und der Beweis, dass das Band sich schliesst: Zwischen dem oeffnenden
 * <form> des Bandes und der Tabelle steht ein </form>. Ohne diese Pruefung
 * waere die vorige nur noch eine Zaehlung im Rumpf -- sie saehe eine
 * Verschachtelung nicht, die genau die Knoepfe stillstellte, die sie zaehlt.
 */
$bandAnfang = strpos($secondSightingTable, '<form class="estab-tabelle-suchband"');
if ($bandAnfang === false) {
    $bandAnfang = strpos($secondSightingTable, '<form');
}
$tabellenAnfang = strpos($secondSightingTable, '<table');
$assert(
    is_int($bandAnfang) && is_int($tabellenAnfang)
        && $bandAnfang < $tabellenAnfang
        && ($bandEnde = strpos($secondSightingTable, '</form>', $bandAnfang)) !== false
        && $bandEnde < $tabellenAnfang,
    'Das Suchband schliesst sich nicht vor der Tabelle; die Formulare in den '
        . 'Zeilen laegen darin und waeren wirkungslos.'
);

$emptyDefault = $render(static function (): void {
    estab_message_list_render_empty(estab_message_list_default_filters());
});
$emptyFiltered = $render(static function () use ($filters): void {
    estab_message_list_render_empty($filters);
});
$assert(
    str_contains($emptyDefault, 'Noch keine Nachrichten')
        && str_contains($emptyFiltered, 'Keine passenden Nachrichten')
        && str_contains($emptyFiltered, 'Ändern oder entfernen Sie Filter'),
    'Empty state does not distinguish an empty incident from empty results'
);

// Ohne Kommentare, siehe tests/php/lib/quelltext.php.
$overviewSource = estab_test_ohne_kommentare(
    (string) file_get_contents($root . '/4fueltg/ue_ltg.php')
);
$listSource = file_get_contents($root . '/4fach/liste.php');
$mainSource = file_get_contents($root . '/4fach/mainindex.php');
$toolsSource = file_get_contents($root . '/4fach/tools.php');
$stylesheet = file_get_contents($root . '/estab-ui.css');
$browserSource = file_get_contents($root . '/tests/browser/headless_ui.py');
$ciSource = file_get_contents($root . '/tests/integration/ci.sh');
foreach (
    [
        $overviewSource,
        $listSource,
        $mainSource,
        $toolsSource,
        $stylesheet,
        $browserSource,
        $ciSource,
    ]
    as $source
) {
    $assert(is_string($source), 'Message-list source is unreadable');
}

$assert(
    str_contains($browserSource, '--message-overview')
        && str_contains(
            $browserSource,
            'ESTAB_TEST_MESSAGE_OVERVIEW_SUBJECT'
        )
        && str_contains($browserSource, 'data-estab-message-list-heading')
        && str_contains($ciSource, '--message-overview')
        && str_contains(
            $ciSource,
            "ESTAB_TEST_LOGIN_FUNCTION=S2"
        )
        && str_contains(
            $ciSource,
            "ESTAB_TEST_MESSAGE_OVERVIEW_SUBJECT='Sicherer UTF-8-Betreff äöü'"
        ),
    'Real-browser heading acceptance is not wired into the S2 CI gate'
);

/** Remove migration-reference comments before inspecting executable branches. */
$withoutPhpComments = static function (string $source): string {
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_string($token)) {
            $code .= $token;
            continue;
        }
        if (!in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $code .= $token[1];
        }
    }
    return $code;
};

/*
 * Ergebnisleiste und Blaetterer kommen seit der Umstellung aus dem
 * Tabellenbauteil, nicht mehr aus zwei eigenen Ausgaben der
 * Meldungsschicht. Die Uebersicht ruft sie deshalb nicht mehr auf -- sie
 * uebergibt dem Bauteil ihre Auswahl ("fremd") samt Zaehlung, und das
 * Bauteil zeichnet beides.
 *
 * Geprueft bleibt der Punkt, um den es dabei geht: Die Uebersicht baut
 * nichts davon selbst. Traege sie wieder einen eigenen Blaetterer, stuenden
 * auf einer Seite zwei -- und die Uneinheitlichkeit, wegen der umgestellt
 * wurde, waere zurueck.
 */
$assert(
    str_contains($overviewSource, 'require_once __DIR__ . "/../app/message_list_ui.php"')
        && str_contains($overviewSource, 'data-estab-message-overview data-estab-message-list')
        && str_contains($overviewSource, 'estab_message_list_render_controls (')
        && str_contains($overviewSource, '"nur_felder" => true')
        && str_contains($overviewSource, 'estab_message_list_render_table ('),
    'Meldungsübersicht bypasses the shared message-list renderers'
);
$assert(
    !str_contains($overviewSource, 'estab_message_list_render_resultbar (')
        && !str_contains($overviewSource, 'estab_message_list_render_pager ('),
    'Die Meldungsübersicht zeichnet Ergebnisleiste oder Blätterer wieder '
        . 'selbst; das Tabellenbauteil bringt beide mit, und zwei nebeneinander '
        . 'sind genau die Uneinheitlichkeit, die abgestellt werden sollte.'
);
/*
 * In der Anzeige steht der Anzeigename, im Feld der gespeicherte
 * Schluessel -- und beides an der richtigen Stelle.
 *
 * Geprueft wurde das einmal an der zweiten Vordruckfassung der
 * Uebersicht. Die ist geloescht (rm_ein_vordruck); die Uebersicht baut
 * jetzt den gepflegten Vordruck, und geprueft wird dort.
 */
$vordruckQuelle = (string) file_get_contents($root . '/4fach/4fachform.php')
    . (string) file_get_contents($root . '/4fach/official_message_form.php');
$assert(
    // Beide Schreibweisen: Der aeltere Teil setzt ein Leerzeichen vor die
    // Klammer, der neuere nicht. Gemeint ist dieselbe Funktion.
    (substr_count($vordruckQuelle, 'estab_function_display_name (')
        + substr_count($vordruckQuelle, 'estab_function_display_name(')) >= 5
        && str_contains(
            $vordruckQuelle,
            'name=\"14_funktion\" value=\"".$this->safe_message_value'
        )
        && str_contains($overviewSource, '/../4fach/4fachform.php')
        // Die Kontenliste kommt aus dem Tabellenbauteil; sie uebersetzt die
        // Funktion beim Bauen der Zeile statt beim Ausgeben der Zelle. Die
        // Aussage ist dieselbe: In der Liste steht der Anzeigename, nicht
        // der gespeicherte Schluessel.
        && str_contains($toolsSource, '"funktion" => estab_function_display_name (')
        && !preg_match(
            '~"funktion" => \(string\) \(\$user \["funktion"\]~',
            $toolsSource
        ),
    'overview detail or account list exposes the persisted function key'
);
$recipientInclude = strpos(
    $overviewSource,
    'include ("../4fcfg/fkt_rolle.inc.php");'
);
$recipientRequestParser = strpos(
    $overviewSource,
    '$overviewRecipientFunctions = array ();'
);
$assert(
    is_int($recipientInclude)
        && is_int($recipientRequestParser)
        && $recipientInclude < $recipientRequestParser,
    'Overview parses the recipient filter before loading its allowlist'
);
$overviewGetListStart = strpos($overviewSource, 'function get_list ()');
$overviewGetListEnd = $overviewGetListStart === false
    ? false
    : strpos($overviewSource, 'function legacy_get_list ()', $overviewGetListStart);
$overviewGetList = (
    is_int($overviewGetListStart)
    && is_int($overviewGetListEnd)
    && $overviewGetListEnd > $overviewGetListStart
) ? substr(
    $overviewSource,
    $overviewGetListStart,
    $overviewGetListEnd - $overviewGetListStart
) : '';
$overviewTableConfig = strpos(
    $overviewGetList,
    'require __DIR__ . "/../4fcfg/dbcfg.inc.php";'
);
$overviewTableUse = strpos(
    $overviewGetList,
    '$conf_4f_tbl ["nachrichten"]'
);
$assert(
    $overviewGetList !== ''
        && is_int($overviewTableConfig)
        && is_int($overviewTableUse)
        && $overviewTableConfig < $overviewTableUse
        && str_contains(
            $overviewGetList,
            'estab_message_list_tbb_number_select_sql ("m")'
        )
        && str_contains($overviewGetList, 'm.`12_anhang`')
        && str_contains($overviewGetList, 'm.`12_betreff`'),
    'Overview list method uses table configuration outside its method scope'
);

$adminListStart = strpos($listSource, 'function get_admin_message_list (');
$adminListEnd = $adminListStart === false
    ? false
    : strpos($listSource, 'function get_list (', $adminListStart);
$adminListSource = (
    is_int($adminListStart)
    && is_int($adminListEnd)
    && $adminListEnd > $adminListStart
) ? substr($listSource, $adminListStart, $adminListEnd - $adminListStart) : '';
$assert(
    $adminListSource !== ''
        && str_contains($adminListSource, 'm.`12_anhang`')
        && str_contains($adminListSource, 'm.`12_betreff`'),
    'Second-sighting query omits heading or attachment data from the shared renderer'
);

$assert(
    str_contains(
        $listSource,
        'function estab_list_attachment_badge ($storedReferences)'
    )
        && str_contains(
            $listSource,
            'estab_message_list_attachment_tokens ($storedReferences)'
        )
        && str_contains(
            $listSource,
            'estab_message_list_attachment_label ('
        )
        && str_contains(
            $listSource,
            'data-estab-message-attachment-badge '
        )
        && str_contains(
            $listSource,
            'estab_auth_html ($attachmentLabel)'
        ),
    'Legacy staff attachment badge bypasses canonical parsing, safe labels, or escaping'
);

$listExecutableSource = $withoutPhpComments($listSource);
$staffCaseStart = strrpos($listExecutableSource, 'case "Stab_lesen"');
$staffCaseEnd = $staffCaseStart === false
    ? false
    : strpos($listExecutableSource, 'case "Stab_sichten"', $staffCaseStart);
$staffCase = (
    is_int($staffCaseStart)
    && is_int($staffCaseEnd)
    && $staffCaseEnd > $staffCaseStart
) ? substr(
    $listExecutableSource,
    $staffCaseStart,
    $staffCaseEnd - $staffCaseStart
) : '';
$assert(
    $staffCase !== ''
        && str_contains(
            $staffCase,
            'estab_list_attachment_badge ($row ["12_anhang"] ?? null);'
        )
        && substr_count(
            $staffCase,
            'estab_list_attachment_badge ('
        ) === 1,
    'Staff message rows do not show exactly one attachment badge'
);
/*
 * Jede Betriebsliste zeigt an, dass eine Anlage haengt.
 *
 * Gezaehlt werden beide Schreibweisen. Ausgang und Disposition holen ihre
 * Zeilen seit der Umstellung durch das Tabellenbauteil; dort heisst das
 * Feld `$z ["anhang"]`, weil die Zeile schon aufbereitet ist. Die Zahl
 * bleibt dieselbe -- nur die Schreibweise nicht, und eine Pruefung, die
 * eine Zeichenkette zaehlt, darf das nicht mit einem Verlust verwechseln.
 */
$anlagenmarken = substr_count(
    $listExecutableSource,
    'estab_list_attachment_badge ($row ["12_anhang"] ?? null);'
) + substr_count(
    $listExecutableSource,
    'estab_list_attachment_badge ($z ["anhang"] ?? null);'
);
/*
 * Vier statt sieben: Die drei Nachweisungszweige in liste.php sind
 * geloescht -- niemand rief sie auf (siehe ges_tabelle_einheitlich).
 *
 * Die Anlagenangabe ist damit nicht verschwunden, sondern umgezogen. Die
 * lebende Nachweisung in app/nachweisung.php haengt sie an den Inhalt --
 * als Text ("... . 2 Anlagen"), nicht als Marke: Sie liefert dem Bauteil
 * reinen Text, damit dieses maskiert, auf zwei Zeilen kuerzt und den Rest
 * aufklappt. Eine Seite, die fertiges Markup liefert, umgeht die
 * Maskierung.
 *
 * Beide Haelften werden geprueft. Nur die erste hiesse: Die Nachweisung
 * duerfte verschweigen, dass eine Anlage haengt -- und im Nachweis ist
 * das keine Nebensache.
 */
$assert(
    $anlagenmarken === 4
        && substr_count($listExecutableSource, '`12_anhang`') >= 4,
    'An operational message list omits the attachment indicator ('
        . $anlagenmarken . ' statt 4)'
);
$nachweisungQuelle = (string) file_get_contents($root . '/app/nachweisung.php');
$assert(
    str_contains($nachweisungQuelle, 'estab_message_list_attachment_tokens')
        && str_contains($nachweisungQuelle, 'estab_message_list_attachment_label')
        && str_contains($nachweisungQuelle, "\$zeile['12_anhang']"),
    'Die Nachweisung verschweigt, dass eine Anlage haengt.'
);

/*
 * Hier standen drei Pruefungen an den Nachweisungszweigen von
 * liste.php: dass jeder die Anlagendaten liest und genau eine
 * Anlagenmarke setzt. Die Zweige sind geloescht -- niemand rief sie
 * auf (siehe ges_tabelle_einheitlich, "kein Listenzweig ohne
 * Aufrufer").
 *
 * Die Anforderung steht oben, an der lebenden Nachweisung: Sie liest
 * 12_anhang und sagt am Inhalt, dass eine Anlage haengt.
 */
$secondCaseStart = strrpos($listExecutableSource, 'case "SIADMIN"');
/*
 * Der Abschnitt endete an 'case "FmNwE"'. Den gibt es nicht mehr.
 *
 * Als neues Ende dient das `break;` des Zweiges. Nicht `} // switch`:
 * Der Quelltext ist hier ohne Kommentare, und die Marke waere darin
 * nicht zu finden -- die Isolierung liefe bis zum Dateiende und die
 * Pruefung saehe Dinge, die einem anderen Zweig gehoeren.
 */
$secondCaseEnd = $secondCaseStart === false
    ? false
    : strpos($listExecutableSource, 'break;', $secondCaseStart);
$secondCase = (
    is_int($secondCaseStart)
    && is_int($secondCaseEnd)
    && $secondCaseEnd > $secondCaseStart
) ? substr(
    $listExecutableSource,
    $secondCaseStart,
    $secondCaseEnd - $secondCaseStart
) : '';
$assert(
    $secondCase !== ''
        && str_contains($listSource, 'message_list_ui.php')
        && str_contains(
            $listExecutableSource,
            'estab_message_list_tbb_number_select_sql ("m")'
        )
        && str_contains($secondCase, 'estab_message_list_render_controls')
        && str_contains($secondCase, 'estab_message_list_render_resultbar')
        && str_contains($secondCase, 'estab_message_list_render_table')
        && str_contains($secondCase, 'estab_message_list_render_pager'),
    'Second sighting bypasses the shared message-list renderers'
);
$assert(
    !str_contains($secondCase, 'type=\"image\"')
        && !str_contains($secondCase, 'button.php')
        && !str_contains($secondCase, 'listen_navi')
        && !str_contains($secondCase, 'darstellungs_art')
        && !preg_match('/http-equiv=.*refresh/i', $secondCase),
    'Second-sighting result branch retains image buttons or auto-refresh'
);

/*
 * Eine Bedienung, nicht zwei.
 *
 * Die zweite Sichtung und die Korrekturliste stehen im Nachrichtenteil.
 * Dessen Steuerlauf bindet jede Anfrage mit Listenfeldern an POST, an ein
 * Token und an die gewaehlte Ansicht -- deshalb bringen beide ihre
 * Bedienung als POST-Formulare mit. Das Band des Tabellenbauteils spricht
 * GET; stand es daneben, endete jede Suche darin mit "Aktion nicht
 * erlaubt", und der Bediener sah nur eine abgewiesene Anfrage.
 *
 * Die Uebersicht der Uebungsleitung ist der andere Fall: Sie ist ganz auf
 * GET gebaut und laesst die Baender des Bauteils stehen. Deshalb steht der
 * Schalter am Aufruf und nicht im Bauteil.
 */
$listUiSource = (string) file_get_contents($root . '/app/message_list_ui.php');
$korrekturStart = strrpos($listExecutableSource, 'case "KORREKTUR"');
$korrekturEnde = $korrekturStart === false
    ? false
    : strpos($listExecutableSource, 'break;', $korrekturStart);
$korrekturZweig = (
    is_int($korrekturStart)
    && is_int($korrekturEnde)
    && $korrekturEnde > $korrekturStart
) ? substr(
    $listExecutableSource,
    $korrekturStart,
    $korrekturEnde - $korrekturStart
) : '';
$assert(
    str_contains($secondCase, 'eigeneBedienung: true')
        && $korrekturZweig !== ''
        && str_contains($korrekturZweig, 'estab_message_list_render_table')
        && str_contains($korrekturZweig, 'eigeneBedienung: true')
        && str_contains($listUiSource, '\'baender\' => !$eigeneBedienung,'),
    'Zweite Sichtung oder Korrekturliste zeichnet wieder das GET-Band des '
        . 'Tabellenbauteils neben ihre eigene Bedienung; dessen Suche endet '
        . 'im Nachrichtenteil mit "Aktion nicht erlaubt".'
);

$secondShellStart = strpos($mainSource, '2. SICHTUNG');
$secondShellEnd = $secondShellStart === false
    ? false
    : strpos($mainSource, 'Nachricht als Sichtung anzeigen', $secondShellStart);
$secondShell = (
    is_int($secondShellStart)
    && is_int($secondShellEnd)
    && $secondShellEnd > $secondShellStart
) ? substr($mainSource, $secondShellStart, $secondShellEnd - $secondShellStart) : '';
$assert(
    $secondShell !== ''
        && str_contains($secondShell, 'data-estab-second-sighting=\"aw\"')
        && str_contains($secondShell, 'data-estab-second-sighting=\"si\"')
        && substr_count($secondShell, "\n          true\n        );") === 2
        && str_contains($toolsSource, 'estab_session_ui_stylesheet ()."\\n"')
        && !str_contains($secondShell, '"si2liste"')
        && !preg_match('/http-equiv=.*refresh/i', $secondShell)
        && !str_contains($secondShell, 'type=\"image\"'),
    'Second-sighting shell is not shared or still forces legacy refresh UI'
);
$assert(
    str_contains(
        $secondShell,
        '($workflowSelectedIdentity ["funktion"] ?? null) === "A/W"'
    )
        && str_contains(
            $secondShell,
            '($workflowSelectedIdentity ["rolle"] ?? null) === "Fernmelder"'
        )
        && str_contains(
            $secondShell,
            '($workflowSelectedIdentity ["funktion"] ?? null) === "Si"'
        )
        && str_contains(
            $secondShell,
            '($workflowSelectedIdentity ["rolle"] ?? null) === "Stab"'
        ),
    'Second-sighting branches are not bound to the fixed account function'
);

$cssClasses = [
    'controls', 'search-form', 'search-row', 'query', 'quick-filters',
    'more', 'filter-grid', 'active', 'chip', 'resultbar', 'resultcount',
    'sort-summary', 'table-wrap', 'table', 'row', 'priority', 'status',
    'route', 'correspondents', 'field-label', 'subject', 'summary', 'excerpt',
    'recipients', 'open', 'pager',
    'page-status', 'empty', 'updated',
];
foreach ($cssClasses as $class) {
    $assert(
        str_contains($stylesheet, '.estab-message-list-' . $class),
        'Shared stylesheet lacks message-list class: ' . $class
    );
}
$assert(
    preg_match(
        '/\.estab-message-list-table thead th\s*\{[^}]*'
            . 'position:\s*sticky;[^}]*top:\s*0;/s',
        $stylesheet
    ) === 1
        && str_contains($stylesheet, 'content: attr(data-label)')
        && str_contains($stylesheet, '@media (max-width: 42rem)')
        && str_contains(
            $stylesheet,
            '.estab-message-list-table td > * {'
        )
        && str_contains($stylesheet, 'grid-column: 2;')
        && str_contains(
            $stylesheet,
            '.estab-message-list-table td.estab-message-list-summary::before,'
        )
        && str_contains($stylesheet, 'grid-column: 1 / -1;')
        && str_contains($stylesheet, '.estab-message-list-subject--empty')
        && str_contains($stylesheet, 'overflow-wrap: anywhere;')
        && str_contains($stylesheet, '@media (prefers-reduced-motion: reduce)')
        && str_contains($stylesheet, '@media print')
        && str_contains($stylesheet, 'min-height: 2.75rem'),
    'Message-list CSS lacks sticky, responsive, reduced-motion, print or target sizing'
);
$assert(
    preg_match(
        '/\.estab-message-list-row:hover,[^{]*\{(?<body>[^}]*)\}/s',
        $stylesheet,
        $hoverRule
    ) === 1
        && !str_contains((string) ($hoverRule['body'] ?? ''), 'transform:'),
    'Message-list row hover can overlap neighbouring results'
);
$assert(
    preg_match(
        /*
         * Die Luecken standen hier als Literale -- 0.5rem und 0.45rem. Seit
         * die Masse aus Marken kommen, waere das eine Pruefung auf die
         * Schreibweise. Geprueft wird die Sache: Blaetterer und Markenreihe
         * legen sich auf eine Zeile, brechen um und halten Abstand aus der
         * Skala.
         */
        '/\.estab-message-list-pager > form\s*\{[^}]*display:\s*flex;'
            . '[^}]*gap:\s*var\(--abstand-\d\);[^}]*width:\s*100%;/s',
        $stylesheet
    ) === 1
        && preg_match(
            '/\.estab-message-list-active > div\s*\{[^}]*display:\s*flex;'
                . '[^}]*flex-wrap:\s*wrap;[^}]*gap:\s*var\(--abstand-\d\);/s',
            $stylesheet
        ) === 1
        && substr_count(
            $stylesheet,
            '.estab-message-list-pager > form'
        ) >= 2
        && substr_count(
            $stylesheet,
            '.estab-message-list-active > div'
        ) >= 2,
    'Pager or active-chip wrapper bypasses desktop/mobile layout'
);

echo "message-list UI security: OK ({$assertions} assertions)\n";
