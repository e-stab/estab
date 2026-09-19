<?php

declare(strict_types=1);

/**
 * Prove that the Nachrichtenvordruck numbers its fields the way the printed
 * sheet does.
 *
 * The sheet carries seventeen small numbers in its corners -- the count of
 * the Stab-Unterlage. The Ausfüllanleitung counts twenty explanations; its
 * numbers lead to the right explanation but are not the numbers the fields
 * carry. For a while the form printed the twenty into the corners, and a
 * responder holding the paper next to the screen read two different sheets.
 * This test pins the corners to the paper and the help to the instructions:
 * every corner prints the Unterlage number of the field whose help sits in
 * the same box, three boxes stay blank as on paper, and the unit line
 * carries the 13 that has no instruction of its own.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/nv_field_numbers.php';

if (!function_exists('estab_message_html')) {
    function estab_message_html(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }
}

require_once $root . '/4fach/official_message_form.php';

final class OfficialMessageFieldNumberFixture
{
    use EstabOfficialMessageFormView;

    /** @var array<string,string> */
    public array $formdata = [];

    /** @var array<string,bool> */
    public array $errorselect = [];

    /** @var array<int,bool> */
    public array $feld = [];

    public string $task = 'Stab_schreiben';

    public function safe_message_value(string $field): string
    {
        return estab_message_html($this->formdata[$field] ?? '');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$fixture = new OfficialMessageFieldNumberFixture();

$helpNumbers = array_keys($fixture->official_message_help_definitions());
$assert(
    $helpNumbers === range(1, 20),
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Die Ausfüllhilfen decken nicht die Felder 1 bis 20 ab: '
            . implode(', ', array_map('strval', $helpNumbers))
    )
);

$source = file_get_contents($root . '/4fach/official_message_form.php');
$assert(
    is_string($source) && $source !== '',
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Die Ansicht des Nachrichtenvordrucks ist nicht lesbar.'
    )
);
$source = (string) $source;
$renderStart = strpos($source, 'function plot_official_message_form()');
$assert(
    $renderStart !== false,
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Der Vordruck wird nicht mehr von plot_official_message_form() gesetzt.'
    )
);
$render = substr($source, (int) $renderStart);

/*
 * Walk the rendered grid once and collect two sequences: the help numbers in
 * document order and the corner calls in document order. A stamp block
 * emits both from a single argument. The corner call takes the instruction
 * number and prints the sheet's number; the unit line prints its 13 alone.
 */
preg_match_all(
    '~\$this->official_message_help\((\d+)\)'
        . '|official_message_timestamp_block\(\s*\'[^\']*\',\s*(\d+),'
        . '|\$this->official_message_print_number\((\d+)\)'
        . '|\$this->official_message_print_unit_number\(\)~',
    $render,
    $fieldMarks,
    PREG_SET_ORDER
);
$helpOrder = [];
$cornerOrder = [];
$printedOrder = [];
foreach ($fieldMarks as $fieldMark) {
    $help = $fieldMark[1] ?? '';
    $stamp = $fieldMark[2] ?? '';
    $corner = $fieldMark[3] ?? '';
    if ($help !== '') {
        $helpOrder[] = (int) $help;
        continue;
    }
    if ($stamp !== '') {
        $helpOrder[] = (int) $stamp;
        $cornerOrder[] = (int) $stamp;
        $printedOrder[] = estab_nv_corner_number((int) $stamp);
        continue;
    }
    if ($corner !== '') {
        $cornerOrder[] = (int) $corner;
        $printedOrder[] = estab_nv_corner_number((int) $corner);
        continue;
    }
    $cornerOrder[] = 0;
    $printedOrder[] = ESTAB_NV_UNTERLAGE_EINHEIT;
}

// Jede der zwanzig Hilfen hat genau einen Eckenaufruf mit ihrer Nummer, in
// derselben Reihenfolge -- plus die 13 der Einheitszeile ohne Hilfe.
$sortedHelp = $helpOrder;
sort($sortedHelp, SORT_NUMERIC);
$assert(
    array_values(array_filter($cornerOrder)) === $helpOrder
        && $sortedHelp === range(1, 20),
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Ausfüllhilfen ' . implode(', ', array_map('strval', $helpOrder))
            . ' stehen den Eckenaufrufen '
            . implode(', ', array_map('strval', $cornerOrder))
            . ' gegenüber.'
    )
);
// Gedruckt werden die siebzehn Nummern des Blattes, jede genau einmal;
// vier Ecken bleiben leer wie auf dem Papier: das obere Mittel, die Ruf
// Nr., der Nachrichtentext -- und die Abfassungszeit, deren 12 beim
// Absender steht.
$sheet = array_values(array_filter(
    $printedOrder,
    static fn (?int $number): bool => $number !== null
));
sort($sheet, SORT_NUMERIC);
$assert(
    $sheet === range(1, 17),
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Die Feldecken drucken ' . implode(', ', array_map('strval', $sheet))
            . ' statt der Nummern 1 bis 17 des Blattes, jede einmal.'
    )
);
$assert(
    count(array_filter($printedOrder, 'is_null')) === 4,
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Auf dem Blatt bleiben vier Ecken leer; der Vordruck lässt '
            . count(array_filter($printedOrder, 'is_null')) . ' leer.'
    )
);
// Kein Aufruf umgeht die Übersetzung mit einer Ziffer im Markup.
$assert(
    preg_match('~estab-official-print-number">\d~', $render) !== 1,
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Der Vordruck schreibt eine Feldnummer wörtlich statt sie über '
            . 'app/nv_field_numbers.php zu beziehen.'
    )
);

// Was der Eckenaufruf druckt, und was er auslässt.
$probe = new OfficialMessageFieldNumberFixture();
foreach ([1 => '', 9 => '8', 11 => '', 14 => '', 15 => '12', 16 => '', 20 => '17'] as $number => $expected) {
    ob_start();
    $probe->official_message_print_number($number);
    $cornerMarkup = (string) ob_get_clean();
    $assert(
        $cornerMarkup === ($expected === ''
            ? ''
            : '<span class="estab-official-print-number">' . $expected . '</span>'),
        estab_dv_requirement(
            'NV-FELDNUMMERN',
            'Die Ecke des Feldes ' . $number . ' der Ausfüllanleitung druckt „'
                . strip_tags($cornerMarkup) . '“ statt „' . $expected . '“.'
        )
    );
}
ob_start();
$probe->official_message_print_unit_number();
$assert(
    (string) ob_get_clean() === '<span class="estab-official-print-number">13</span>',
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Die Zeile Einheit/Einrichtung/Stelle trägt nicht die 13 des Blattes.'
    )
);

// Die Hilfe heisst wie das Blatt und nennt die Anleitung nur als Fundstelle.
ob_start();
$probe->official_message_help(9);
$helpNine = (string) ob_get_clean();
ob_start();
$probe->official_message_help(11);
$helpEleven = (string) ob_get_clean();
$assert(
    str_contains($helpNine, 'aria-label="Ausfüllhilfe zu Feld 8 · Vorrangstufe öffnen"')
        && str_contains($helpNine, '-title">Feld 8 · Vorrangstufe</strong>')
        && str_contains($helpNine, 'Ausfüllanleitung, Nr. 9</span>')
        && !str_contains($helpNine, '>9 · ')
        && str_contains($helpEleven, 'aria-label="Ausfüllhilfe zu Ruf Nr. öffnen"')
        && str_contains($helpEleven, '-title">Ruf Nr.</strong>')
        && str_contains($helpEleven, 'Ausfüllanleitung, Nr. 11</span>'),
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Die Ausfüllhilfe nennt das Feld nicht mit der Nummer des Blattes '
            . 'oder verschweigt ihre Fundstelle in der Ausfüllanleitung.'
    )
);

// Die Fehlerliste nennt die Nummer des Blattes -- und ohne Nummer den Namen:
// Der Nachrichtentext ist Feld 14 der Anleitung, auf dem Blatt unbeziffert.
$probe->task = 'Stab_schreiben';
$probe->errorselect = ['10_anschrift' => false, '12_inhalt' => false];
ob_start();
$probe->official_message_error_summary();
$summary = (string) ob_get_clean();
$assert(
    str_contains($summary, '<strong>Feld 9 · Anschrift</strong>')
        && !str_contains($summary, 'Feld 10')
        && !str_contains($summary, 'Feld 14')
        && str_contains($summary, '<strong>Nachricht, Text</strong>'),
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Die Fehlerliste nennt eine Nummer, die das Blatt nicht druckt.'
    )
);

/**
 * @return array{help:list<int>,printed:list<int>}
 */
$numbersBetween = static function (
    string $grid,
    string $from,
    string $to
): array {
    $start = strpos($grid, $from);
    $end = $start === false ? false : strpos($grid, $to, $start + strlen($from));
    if ($start === false || $end === false) {
        return ['help' => [], 'printed' => []];
    }
    $block = substr($grid, $start, $end - $start);
    preg_match_all(
        '~\$this->official_message_help\((\d+)\)~',
        $block,
        $helpHits
    );
    preg_match_all(
        '~\$this->official_message_print_number\((\d+)\)'
            . '|\$this->official_message_print_unit_number\(\)~',
        $block,
        $cornerHits,
        PREG_SET_ORDER
    );
    $printed = [];
    foreach ($cornerHits as $hit) {
        $number = ($hit[1] ?? '') !== ''
            ? estab_nv_corner_number((int) $hit[1])
            : ESTAB_NV_UNTERLAGE_EINHEIT;
        if ($number !== null) {
            $printed[] = $number;
        }
    }
    return [
        'help' => array_map('intval', $helpHits[1]),
        'printed' => $printed,
    ];
};

/*
 * Cells the paper grid draws next to each other each show the number the
 * paper prints there; the Ruf Nr., the message text and the actual medium
 * carry none, and the unit line carries the 13 without a help of its own.
 */
$groupedCells = [
    'Anschrift, Ruf Nr. und Gesprächsnotiz' => [
        'estab-official-address-block',
        'estab-official-subject',
        [10, 11, 12],
        [9, 10],
    ],
    'Inhalt aus Betreff und Nachrichtentext' => [
        'estab-official-subject',
        'estab-official-sender',
        [13, 14],
        [11],
    ],
    'Absender und Abfassungszeit' => [
        'estab-official-sender',
        'estab-official-author',
        [15, 16],
        [12],
    ],
    'Verfasserzeile' => [
        'estab-official-author',
        'estab-official-zone--review',
        [17],
        [13, 14],
    ],
];
foreach ($groupedCells as $cellName => [$from, $to, $expectedHelp, $expectedPrinted]) {
    $numbers = $numbersBetween($render, $from, $to);
    $assert(
        $numbers['help'] === $expectedHelp
            && $numbers['printed'] === $expectedPrinted,
        estab_dv_requirement(
            'NV-FELDNUMMERN',
            'Der Bereich ' . $cellName . ' zeigt die Hilfen '
                . implode(', ', array_map('strval', $numbers['help']))
                . ' und die Nummern '
                . implode(', ', array_map('strval', $numbers['printed']))
                . ' statt ' . implode(', ', array_map('strval', $expectedHelp))
                . ' und ' . implode(', ', array_map('strval', $expectedPrinted))
                . '.'
        )
    );
}

$actualMedium = $numbersBetween(
    $render,
    'estab-official-actual-medium',
    'estab-official-ttb'
);
$assert(
    $actualMedium['help'] === [1] && $actualMedium['printed'] === [],
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Das tatsächlich verwendete Übermittlungsmittel trägt die Nummern '
            . implode(', ', array_map('strval', $actualMedium['printed']))
            . '; auf dem Blatt trägt es keine.'
    )
);

/*
 * One number per stamp: the block prints what its help button announces, so
 * the caller cannot pair a printed number with a foreign instruction again.
 */
$stampParameters = (new ReflectionMethod(
    OfficialMessageFieldNumberFixture::class,
    'official_message_timestamp_block'
))->getParameters();
$stampNumbers = array_values(array_filter(
    $stampParameters,
    static fn(ReflectionParameter $parameter): bool
        => (string) $parameter->getType() === 'int'
));
$assert(
    count($stampNumbers) === 1,
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Der Vermerkblock nimmt ' . count($stampNumbers)
            . ' Feldnummern entgegen; er trägt genau eine.'
    )
);

$fixture->formdata = [
    '01_datum' => '311845Jul2026',
    '01_zeichen' => 'aw',
    '02_zeit' => '1846',
    '03_datum' => '311847Jul2026',
];
foreach ([
    2 => ['Aufnahmevermerk', '01_datum'],
    3 => ['Annahmevermerk', '02_zeit'],
    4 => ['Beförderungsvermerk', '03_datum'],
] as $number => [$title, $timeField]) {
    ob_start();
    $fixture->official_message_timestamp_block(
        $title,
        $number,
        $timeField,
        '01_zeichen',
        true,
        'Datum und Uhrzeit',
        'Namenszeichen'
    );
    $stampMarkup = (string) ob_get_clean();
    $assert(
        substr_count($stampMarkup, 'data-estab-form-help="' . $number . '"') === 1
            && substr_count(
                $stampMarkup,
                '<span class="estab-official-print-number">'
                    . estab_nv_corner_number($number) . '</span>'
            ) === 1
            && preg_match_all(
                '~estab-official-print-number">(\d+)<~',
                $stampMarkup
            ) === 1,
        estab_dv_requirement(
            'NV-FELDNUMMERN',
            'Der ' . $title . ' druckt nicht ausschließlich die Nummer '
                . estab_nv_corner_number($number) . ' des Blattes.'
        )
    );
}

/*
 * Field 16 only becomes visible if its box establishes the containing block
 * the corner number is positioned against, and the number itself only lands
 * in that box while its own rule keeps position: absolute.
 */
$css = file_get_contents($root . '/estab-ui.css');
$assert(
    is_string($css) && $css !== '',
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Das Stylesheet des Vordrucks ist nicht lesbar.'
    )
);
$css = (string) $css;
$cssRule = static function (string $sheet, string $selector): string {
    $start = strpos($sheet, $selector . ' {');
    $end = $start === false ? false : strpos($sheet, '}', $start);
    return ($start === false || $end === false)
        ? ''
        : substr($sheet, $start, $end - $start);
};
$assert(
    str_contains(
        $cssRule($css, '.estab-official-composition'),
        'position: relative;'
    )
        && str_contains(
            $cssRule($css, '.estab-official-print-number'),
            'position: absolute;'
        ),
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Die Abfassungszeit stellt der Feldnummer keinen Bezugsrahmen, '
            . 'die Nummer 16 landet ausserhalb ihres Feldes.'
    )
);

echo 'Official message field numbering: OK (' . $assertions
    . " assertions)\n";
