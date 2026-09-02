<?php

declare(strict_types=1);

/**
 * A printed message form must not lose part of the record.
 *
 * The paper form has room for the twenty official fields and nothing else, so
 * the print rules hide the interactive panels. That silently dropped the
 * attachments and the additional recipients from every printout, although both
 * belong to the record. The annex prints them as a plain list on its own page.
 */

$root = dirname(__DIR__, 2);

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
require_once $root . '/app/file_access.php';
require_once $root . '/app/attachment.php';
require_once $root . '/app/attachment_upload.php';

final class OfficialMessagePrintAnnexFixture
{
    use EstabOfficialMessageFormView;

    /** @var array<string,string> */
    public array $formdata = [];

    /** @var array<string,bool> */
    public array $errorselect = [];

    /** @var array<int,bool> */
    public array $feld = [];

    /** @var array<int,array<int,array<string,mixed>>> */
    public array $empfarray = [];

    /** @var array<string,array<string,mixed>> */
    public array $attachmentPreviews = [];

    /** @var list<array<string,mixed>> */
    public array $activeTelecomRoutes = [];

    public string $task = 'Stab_lesen';

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
$render = static function (callable $callback): string {
    ob_start();
    try {
        $callback();
        $markup = ob_get_contents();
        return is_string($markup) ? $markup : '';
    } finally {
        ob_end_clean();
    }
};

// A message without attachments and without extra recipients gets no annex.
$fixture = new OfficialMessagePrintAnnexFixture();
$fixture->formdata = ['04_nummer' => '9142', '12_anhang' => ''];
$fixture->empfarray = [];
$empty = $render(static fn () => $fixture->official_message_print_annex());
$assert(
    $empty === '',
    'An annex is printed although there is nothing to add to the record'
);

// Attachments must reach the printout.
$fixture->formdata = [
    '04_nummer' => '9142',
    '12_betreff' => 'Lagemeldung Abschnitt Nord',
    '12_anhang' => 'EL0001.pdf;EL0002.txt',
];
$withAttachments = $render(
    static fn () => $fixture->official_message_print_annex()
);
$assert(
    str_contains($withAttachments, 'estab-message-print-annex'),
    'The annex is not rendered although the message carries attachments'
);
foreach (['EL0001.pdf', 'EL0002.txt'] as $reference) {
    $assert(
        str_contains($withAttachments, $reference),
        'The printed annex omits the attachment ' . $reference
    );
}
$assert(
    str_contains($withAttachments, 'Anlagen (2)'),
    'The printed annex does not state how many attachments belong to the form'
);
$assert(
    str_contains($withAttachments, '9142'),
    'The printed annex does not name the message it belongs to'
);
$assert(
    str_contains($withAttachments, 'Lagemeldung Abschnitt Nord'),
    'The printed annex does not carry the subject of its message'
);

// An unusable reference must not reach the printout.
$fixture->formdata = [
    '04_nummer' => '9142',
    '12_anhang' => '../../etc/passwd;EL0003.pdf',
];
$hostile = $render(static fn () => $fixture->official_message_print_annex());
$assert(
    !str_contains($hostile, 'passwd') && str_contains($hostile, 'EL0003.pdf'),
    'The printed annex takes over an unvalidated attachment reference'
);

// Additional recipients belong to the record as well.
$fixture->formdata = [
    '04_nummer' => '9143',
    '12_anhang' => '',
    '16_empf' => 'FB-Wasser_bl,VB-POL_gn,',
];
$fixture->empfarray = [
    1 => [
        1 => ['fkt' => 'LS', 'rolle' => 'Stab'],
        2 => ['fkt' => 'S1', 'rolle' => 'Stab'],
        3 => ['fkt' => 'S2', 'rolle' => 'Stab'],
        4 => ['fkt' => 'S3', 'rolle' => 'Stab'],
    ],
    2 => [
        1 => ['fkt' => 'S4', 'rolle' => 'Stab'],
        2 => ['fkt' => 'S5', 'rolle' => 'Stab'],
        3 => ['fkt' => 'S6', 'rolle' => 'Stab'],
        4 => ['fkt' => 'FB-Wasser', 'rolle' => 'Stab'],
    ],
];
$withExtras = $render(static fn () => $fixture->official_message_print_annex());
$assert(
    str_contains($withExtras, 'Weitere Empfänger'),
    'The printed annex omits the additional recipients entirely'
);
$assert(
    str_contains($withExtras, 'FB-Wasser'),
    'The printed annex omits an additional recipient that carries a copy'
);
$assert(
    str_contains($withExtras, 'blau'),
    'The printed annex does not name the carbon copy of a recipient'
);

// The annex must stay out of the way while working.
$stylesheet = file_get_contents($root . '/estab-ui.css');
if (!is_string($stylesheet)) {
    throw new RuntimeException('Could not read estab-ui.css');
}
$assert(
    preg_match(
        '~\.estab-message-print-annex\s*\{[^}]*display:\s*none~',
        $stylesheet
    ) === 1,
    'The annex is visible on screen and clutters the working view'
);
$printBlock = '';
$offset = strpos($stylesheet, '@media print');
while ($offset !== false) {
    $braceStart = strpos($stylesheet, '{', $offset);
    if ($braceStart === false) {
        break;
    }
    $depth = 0;
    $length = strlen($stylesheet);
    for ($index = $braceStart; $index < $length; $index++) {
        if ($stylesheet[$index] === '{') {
            $depth++;
        } elseif ($stylesheet[$index] === '}') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
    }
    $candidate = substr($stylesheet, $braceStart, $index - $braceStart);
    if (str_contains($candidate, '.estab-message-print-annex')) {
        $printBlock = $candidate;
        break;
    }
    $offset = strpos($stylesheet, '@media print', $index);
}
$assert(
    $printBlock !== '' && str_contains($printBlock, 'display: block'),
    'The annex is not printed, so the record stays incomplete on paper'
);
$assert(
    str_contains($printBlock, 'page-break-before')
        || str_contains($printBlock, 'break-before'),
    'The annex does not start on its own page and overruns the form'
);

// The renderer has to be wired into the form, not merely exist.
$formSource = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($formSource)) {
    throw new RuntimeException('Could not read the official message form');
}
$assert(
    substr_count($formSource, '$this->official_message_print_annex();') === 1,
    'The annex renderer is not called exactly once from the form'
);

printf("message print annex: OK (%d assertions)\n", $assertions);
