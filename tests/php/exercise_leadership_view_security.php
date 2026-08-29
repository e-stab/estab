<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/message_repository.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/4fueltg/ue_ltg.php');
$toolsSource = file_get_contents($root . '/4fach/tools.php');
$listUiSource = file_get_contents($root . '/app/message_list_ui.php');
$assert(
    is_string($source)
        && is_string($toolsSource)
        && is_string($listUiSource),
    'exercise leadership view, shared list UI or recipient helper source unreadable'
);

/**
 * Extract one repository-owned function or method for source contracts and
 * focused evaluation without running the legacy controller side effects.
 */
$extractFunction = static function (string $php, string $functionName): string {
    $tokens = token_get_all($php);
    $tokenCount = count($tokens);

    for ($index = 0; $index < $tokenCount; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $nameIndex = $index + 1;
        while (
            $nameIndex < $tokenCount
            && (
                (is_array($tokens[$nameIndex])
                    && in_array($tokens[$nameIndex][0], [T_WHITESPACE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true))
                || $tokens[$nameIndex] === '&'
            )
        ) {
            $nameIndex++;
        }
        if (
            $nameIndex >= $tokenCount
            || !is_array($tokens[$nameIndex])
            || $tokens[$nameIndex][0] !== T_STRING
            || $tokens[$nameIndex][1] !== $functionName
        ) {
            continue;
        }

        $body = '';
        $braceDepth = 0;
        $bodyStarted = false;
        for ($bodyIndex = $index; $bodyIndex < $tokenCount; $bodyIndex++) {
            $bodyToken = $tokens[$bodyIndex];
            $text = is_array($bodyToken) ? $bodyToken[1] : $bodyToken;
            $body .= $text;
            if (!is_array($bodyToken) && $bodyToken === '{') {
                $braceDepth++;
                $bodyStarted = true;
            } elseif (!is_array($bodyToken) && $bodyToken === '}') {
                $braceDepth--;
                if ($bodyStarted && $braceDepth === 0) {
                    return $body;
                }
            }
        }
    }

    throw new RuntimeException("Function {$functionName} not found");
};

$overviewUrlSource = $extractFunction($source, 'estab_overview_url');
$rowStartSource = $extractFunction($source, 'estab_overview_row_start');
$recipientCellSource = $extractFunction($source, 'estab_overview_recipient_cell');
$emptyRowSource = $extractFunction($source, 'estab_overview_empty_row');
$navigationSource = $extractFunction($source, 'listen_navi');
$displayControlsSource = $extractFunction($source, 'darstellungs_art');
$listSource = $extractFunction($source, 'createlist');
$tableSource = $extractFunction(
    $listUiSource,
    'estab_message_list_render_table'
);
/*
 * Die Einzelansicht ist der gepflegte Nachrichtenvordruck.
 *
 * Hier stand einmal die zweite Fassung aus ue_ltg.php. Sie ist geloescht
 * (rm_ein_vordruck); geprueft wird, was jetzt gezeichnet wird. Die
 * Anforderungen darunter sind dieselben geblieben -- nur ihr Ort nicht.
 */
$messageFormSource = (string) file_get_contents(
    $root . '/4fach/official_message_form.php'
);
$recipientMapSource = $extractFunction(
    $toolsSource,
    'estab_recipient_copy_map'
);
$recipientColoursSource = $extractFunction(
    $toolsSource,
    'estab_recipient_copy_colours'
);
$recipientBackgroundSource = $extractFunction(
    $toolsSource,
    'estab_recipient_copy_background'
);
$recipientCellHtmlSource = $extractFunction(
    $toolsSource,
    'estab_recipient_copy_cell_html'
);
// Readable ink is resolved from the same colour table as the background, so
// the cell helper cannot be exercised without its colour arithmetic.
$colourChannelsSource = $extractFunction(
    $toolsSource,
    'estab_colour_channels'
);
$colourLuminanceSource = $extractFunction(
    $toolsSource,
    'estab_colour_relative_luminance'
);
$colourContrastSource = $extractFunction(
    $toolsSource,
    'estab_colour_contrast_ratio'
);
$recipientInkSource = $extractFunction(
    $toolsSource,
    'estab_recipient_copy_ink'
);

$assert(
    preg_match('/\$_SERVER\s*\[\s*["\']PHP_SELF["\']\s*\]/', $source) !== 1,
    'request-controlled PHP_SELF remains in the exercise leadership view'
);
$assert(
    str_contains($overviewUrlSource, 'estab_application_url ("4fueltg/ue_ltg.php")')
        && str_contains($overviewUrlSource, 'PHP_QUERY_RFC3986'),
    'overview URLs are not built from the canonical application route'
);
$assert(
    str_contains($source, 'estab_message_html ($url)')
        && str_contains($source, 'estab_message_html ($pageSizeUrl)')
        && str_contains(
            $messageFormSource,
            'estab_message_html($conf_4f[\'MainURL\'])'
        ),
    'overview links or form actions are emitted without HTML escaping'
);
$plotAnfang = strpos($messageFormSource, 'function plot_official_message_form');
$plot = is_int($plotAnfang) ? substr($messageFormSource, $plotAnfang) : '';
$assert(
    $plot !== ''
        && substr_count($plot, '<body') === 1
        && substr_count($plot, '</body>') === 1,
    'message detail view emits duplicate or unbalanced body elements'
);
/*
 * Der TBB-Nachweis kommt aus der einsatzlokalen Nachweisnummer, nie aus
 * `04_nummer`. Die ist eine technische Ablaufnummer; sie als Buchnummer zu
 * zeigen hiesse, einen Nachweis zu behaupten, den es nicht gibt.
 */
$assert(
    str_contains(
        $messageFormSource,
        '$value = $this->formdata[\'estab_ttb_lfd\'] ?? null;'
    )
        && str_contains($messageFormSource, 'noch kein TBB-Nachweis')
        /*
         * `04_nummer` darf mitlaufen -- der Vordruck traegt sie als
         * verborgenes Feld durch den Rueckweg. Verboten ist, sie zu
         * *zeigen*: Die sichtbare Zelle nennt den Nachweistext, und die
         * einzige Stelle, an der 04_nummer als Wert steht, ist ein
         * type="hidden".
         */
        && str_contains(
            $messageFormSource,
            'estab_message_html($this->official_message_ttb_evidence_text())'
        )
        && substr_count(
            $messageFormSource,
            'safe_message_value(\'04_nummer\')'
        ) === 1
        && str_contains(
            $messageFormSource,
            '<input type="hidden" name="04_nummer" value="'
        ),
    'message detail view still presents the technical message number as TBB evidence'
);
/*
 * Die Vorrangstufe ist eine benannte Gruppe aus Kaestchen. Ist sie nicht
 * zu bearbeiten, steht der Wert in einem verborgenen Feld statt in
 * anklickbaren Knoepfen -- lesbar, aber nicht aus Versehen aenderbar.
 */
$assert(
    str_contains($messageFormSource, 'role="radiogroup" ')
        && str_contains($messageFormSource, 'aria-label="Vorrangstufe" ')
        && substr_count($messageFormSource, 'estab-official-box-choice') >= 3
        && str_contains(
            $messageFormSource,
            '<input type="hidden" name="09_vorrangstufe" value="'
        ),
    'message detail priority is not a square, labelled, read-only group'
);
$assert(
    substr_count($navigationSource, '<form action=') === 1
        && substr_count($navigationSource, '</form>') === 1
        && substr_count($displayControlsSource, '<form action=') === 1
        && substr_count($displayControlsSource, '</form>') === 1,
    'overview controls contain an unbalanced or nested form'
);

/*
 * Die Uebersicht baut ihr Tabellenmarkup nicht mehr selbst -- es kommt aus
 * dem Tabellenbauteil. Geprueft wird deshalb, dass sie es dort holt und
 * dass die Reihenfolge im Bauteil steht: Kopf vor Rumpf. Das ist dieselbe
 * Aussage an der Stelle, an der sie jetzt gilt.
 */
$bauteilSource = (string) file_get_contents(
    __DIR__ . '/../../app/tabelle.php'
);
$headerStart = strpos($bauteilSource, "'<thead><tr>'");
$headerEnd = strpos($bauteilSource, "'</thead><tbody>'");
$bodyEnd = strpos($bauteilSource, "'</tbody></table></div>'");
$assert(
    str_contains($tableSource, 'estab_tabelle_markup(')
        && !str_contains($tableSource, '<thead>')
        && $headerStart !== false
        && $headerEnd !== false
        && $bodyEnd !== false
        && $headerStart < $headerEnd
        && $headerEnd < $bodyEnd,
    'message table has no ordered thead/tbody structure'
);
$assert(
    str_contains($tableSource, "'TBB-Nachweis'")
        && str_contains($tableSource, "'Überschrift und Inhalt'")
        && str_contains($tableSource, 'data-estab-message-list-heading')
        && str_contains(
            $tableSource,
            'estab_message_priority_requires_attention'
        )
        && str_contains(
            $tableSource,
            'estab_message_list_tbb_evidence_label($row)'
        )
        && str_contains($tableSource, 'estab_message_list_recipient_labels')
        && str_contains($tableSource, '$openControl($row)'),
    'shared table bypasses priority, recipient or authenticated detail controls'
);
/*
 * Das Schliessen der Tabelle liegt jetzt im Bauteil; die Uebersicht
 * schliesst weiterhin ihr eigenes Dokument. Beides wird geprueft -- an der
 * Stelle, an der es jeweils steht.
 */
$assert(
    str_contains($bauteilSource, "'</tbody></table></div>'")
        && str_contains($listSource, '</body>\\n</html>'),
    'message table or list document is not closed'
);

foreach ([
    'estab_recipient_copy_map',
    'estab_recipient_copy_colours',
    'estab_recipient_copy_background',
    'estab_colour_channels',
    'estab_colour_relative_luminance',
    'estab_colour_contrast_ratio',
    'estab_recipient_copy_ink',
    'estab_recipient_copy_cell_html',
    'estab_overview_url',
    'estab_overview_row_start',
    'estab_overview_recipient_cell',
    'estab_overview_empty_row',
] as $functionName) {
    eval(match ($functionName) {
        'estab_recipient_copy_map' => $recipientMapSource,
        'estab_recipient_copy_colours' => $recipientColoursSource,
        'estab_recipient_copy_background' => $recipientBackgroundSource,
        'estab_colour_channels' => $colourChannelsSource,
        'estab_colour_relative_luminance' => $colourLuminanceSource,
        'estab_colour_contrast_ratio' => $colourContrastSource,
        'estab_recipient_copy_ink' => $recipientInkSource,
        'estab_recipient_copy_cell_html' => $recipientCellHtmlSource,
        'estab_overview_url' => $overviewUrlSource,
        'estab_overview_row_start' => $rowStartSource,
        'estab_overview_recipient_cell' => $recipientCellSource,
        'estab_overview_empty_row' => $emptyRowSource,
    });
}

$publicUrlBefore = getenv('ESTAB_PUBLIC_URL');
$basePathBefore = getenv('ESTAB_BASE_PATH');
putenv('ESTAB_PUBLIC_URL=/');
putenv('ESTAB_BASE_PATH=exercise-test');
$_SERVER['PHP_SELF'] = '/4fueltg/ue_ltg.php/%22%3E%3Csvg%20onload=alert(1)%3E';

$canonicalUrl = estab_overview_url(['ueb_fm' => 'ueb', '00_lfd' => 42]);
$assert(
    $canonicalUrl === '/exercise-test/4fueltg/ue_ltg.php?ueb_fm=ueb&00_lfd=42'
        && !str_contains($canonicalUrl, 'PHP_SELF')
        && !str_contains($canonicalUrl, '<svg'),
    'request path changed the canonical exercise leadership URL'
);
$encodedProbeUrl = estab_overview_url(['probe' => '"><script>&']);
$assert(
    $encodedProbeUrl === '/exercise-test/4fueltg/ue_ltg.php?probe=%22%3E%3Cscript%3E%26'
        && estab_message_html($canonicalUrl)
            === '/exercise-test/4fueltg/ue_ltg.php?ueb_fm=ueb&amp;00_lfd=42',
    'overview query or HTML attribute encoding changed'
);

if ($publicUrlBefore === false) {
    putenv('ESTAB_PUBLIC_URL');
} else {
    putenv('ESTAB_PUBLIC_URL=' . $publicUrlBefore);
}
if ($basePathBefore === false) {
    putenv('ESTAB_BASE_PATH');
} else {
    putenv('ESTAB_BASE_PATH=' . $basePathBefore);
}

/**
 * DOM-near row check that needs no optional ext-dom package. It verifies the
 * only table tags emitted by each representative branch as a strict stack.
 */
$assertRowStructure = static function (
    string $html,
    int $expectedCells,
    string $branch
) use ($assert): void {
    preg_match_all('/<\/?(?:tr|td)\b[^>]*>/i', $html, $matches);
    $stack = [];
    $cells = 0;
    foreach ($matches[0] as $tag) {
        $closing = str_starts_with($tag, '</');
        preg_match('/^<\/?([a-z]+)/i', $tag, $nameMatch);
        $name = strtolower($nameMatch[1] ?? '');
        if (!$closing) {
            if ($name === 'tr') {
                $assert($stack === [], "{$branch}: nested or misplaced tr");
            } elseif ($name === 'td') {
                $assert(end($stack) === 'tr', "{$branch}: td outside tr");
                $cells++;
            }
            $stack[] = $name;
            continue;
        }

        $assert(array_pop($stack) === $name, "{$branch}: mismatched closing {$name}");
    }
    $assert($stack === [], "{$branch}: table tags left open");
    $assert($cells === $expectedCells, "{$branch}: unexpected cell count");
};

$colors = ['rt' => 'rgb(255,0,0)', 'gn' => 'rgb(0,255,0)', 'bl' => 'rgb(0,0,255)'];
$copyMap = estab_recipient_copy_map(
    'AB_C_bl,AB_C_gn,S1_bl,S1_gn,S2_rt,'
);
$assert(
    $copyMap === [
        'AB_C' => 'bl,gn',
        'S1' => 'bl,gn',
        'S2' => 'rt',
    ],
    'underscore recipient functions or multiple copies collapse in list parsing'
);
$normalRow = estab_overview_row_start(false)
    . estab_overview_recipient_cell('', $colors)
    . '</tr>';
$priorityRow = estab_overview_row_start(true)
    . estab_overview_recipient_cell('rt', $colors)
    . '</tr>';
$unknownColorRow = estab_overview_row_start(false)
    . estab_overview_recipient_cell('unexpected', $colors)
    . '</tr>';
$multipleCopyRow = estab_overview_row_start(false)
    . estab_overview_recipient_cell('bl,gn', $colors)
    . '</tr>';
$emptyRow = estab_overview_empty_row(9);

$assertRowStructure($normalRow, 1, 'normal row');
$assertRowStructure($priorityRow, 1, 'priority row');
$assertRowStructure($unknownColorRow, 1, 'unknown recipient color row');
$assertRowStructure($multipleCopyRow, 1, 'multiple-copy recipient row');
$assertRowStructure($emptyRow, 1, 'empty result row');
$assert(
    str_contains($normalRow, 'alt="leer"')
        && str_contains($priorityRow, '>X</td>')
        && str_contains($multipleCopyRow, 'linear-gradient(')
        && str_contains($multipleCopyRow, 'Durchschriften: blau, grün')
        && str_contains($unknownColorRow, 'alt="leer"')
        && str_contains($emptyRow, 'colspan="9"'),
    'representative message table branch content changed'
);

$escapedColorCell = estab_overview_recipient_cell(
    'rt',
    ['rt' => 'red" onmouseover="alert(1)', 'gn' => 'green', 'bl' => 'blue']
);
$assert(
    str_contains($escapedColorCell, 'red&quot; onmouseover=&quot;alert(1)')
        && !str_contains($escapedColorCell, 'onmouseover="alert(1)"'),
    'recipient background escaped its style attribute'
);
try {
    estab_overview_empty_row(0);
    $assert(false, 'invalid empty-row column count accepted');
} catch (InvalidArgumentException) {
    $assert(true, 'invalid empty-row column count rejected');
}

printf("Exercise leadership view security tests: OK (%d assertions)\n", $assertions);
