<?php

declare(strict_types=1);

/**
 * Prove that the Nachrichtenvordruck numbers its fields the way the service
 * regulation does.
 *
 * The printed grid and the filling instructions are one document: a responder
 * reads the number in the corner of a box and looks it up in the Ausfüllan-
 * leitung. As long as the form printed the historic seventeen-field count
 * while the help texts followed the current twenty-field count, every lookup
 * landed on the wrong instruction. This test pins the two scales together.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

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
 * document order and the printed numbers in document order. A stamp block
 * emits both from a single argument, so it contributes to both sequences.
 */
preg_match_all(
    '~\$this->official_message_help\((\d+)\)'
        . '|official_message_timestamp_block\(\s*\'[^\']*\',\s*(\d+),'
        . '|estab-official-print-number">(\d+)<~',
    $render,
    $fieldMarks,
    PREG_SET_ORDER
);
$helpOrder = [];
$printedOrder = [];
foreach ($fieldMarks as $fieldMark) {
    $help = $fieldMark[1] ?? '';
    $stamp = $fieldMark[2] ?? '';
    $printed = $fieldMark[3] ?? '';
    if ($help !== '') {
        $helpOrder[] = (int) $help;
        continue;
    }
    if ($stamp !== '') {
        $helpOrder[] = (int) $stamp;
        $printedOrder[] = (int) $stamp;
        continue;
    }
    $printedOrder[] = (int) $printed;
}

$assert(
    count($printedOrder) === 20,
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Der Vordruck druckt ' . count($printedOrder)
            . ' statt zwanzig Feldnummern.'
    )
);
$sortedPrinted = $printedOrder;
sort($sortedPrinted, SORT_NUMERIC);
$assert(
    $sortedPrinted === range(1, 20),
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Die gedruckten Nummern sind nicht die Felder 1 bis 20: '
            . implode(', ', array_map('strval', $sortedPrinted))
    )
);
$assert(
    $helpOrder === $printedOrder,
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Ausfüllhilfen ' . implode(', ', array_map('strval', $helpOrder))
            . ' stehen den gedruckten Nummern '
            . implode(', ', array_map('strval', $printedOrder))
            . ' gegenüber.'
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
    preg_match_all('~estab-official-print-number">(\d+)<~', $block, $printHits);
    return [
        'help' => array_map('intval', $helpHits[1]),
        'printed' => array_map('intval', $printHits[1]),
    ];
};

/*
 * Cells the paper grid draws next to each other must each show their own
 * number; a shared box would leave one field of the pair unnumbered.
 */
$groupedCells = [
    'Anschrift, Ruf Nr. und Gesprächsnotiz' => [
        'estab-official-address-block',
        'estab-official-subject',
        [10, 11, 12],
    ],
    'Inhalt aus Betreff und Nachrichtentext' => [
        'estab-official-subject',
        'estab-official-sender',
        [13, 14],
    ],
    'Absender und Abfassungszeit' => [
        'estab-official-sender',
        'estab-official-author',
        [15, 16],
    ],
    'Verfasserzeile' => [
        'estab-official-author',
        'estab-official-zone--review',
        [17],
    ],
];
foreach ($groupedCells as $cellName => [$from, $to, $expected]) {
    $numbers = $numbersBetween($render, $from, $to);
    $assert(
        $numbers['help'] === $expected && $numbers['printed'] === $expected,
        estab_dv_requirement(
            'NV-FELDNUMMERN',
            'Der Bereich ' . $cellName . ' zeigt die Hilfen '
                . implode(', ', array_map('strval', $numbers['help']))
                . ' und die Nummern '
                . implode(', ', array_map('strval', $numbers['printed']))
                . ' statt ' . implode(', ', array_map('strval', $expected)) . '.'
        )
    );
}

$actualMedium = $numbersBetween(
    $render,
    'estab-official-actual-medium',
    'estab-official-ttb'
);
$assert(
    $actualMedium['help'] === [1] && $actualMedium['printed'] === [1],
    estab_dv_requirement(
        'NV-FELDNUMMERN',
        'Das tatsächlich verwendete Übermittlungsmittel trägt die Nummern '
            . implode(', ', array_map('strval', $actualMedium['printed']))
            . ' statt der Feldnummer 1.'
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
                '<span class="estab-official-print-number">' . $number
                    . '</span>'
            ) === 1
            && preg_match_all(
                '~estab-official-print-number">(\d+)<~',
                $stampMarkup
            ) === 1,
        estab_dv_requirement(
            'NV-FELDNUMMERN',
            'Der ' . $title . ' druckt nicht ausschließlich die Nummer '
                . $number . ' seiner Ausfüllhilfe.'
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
