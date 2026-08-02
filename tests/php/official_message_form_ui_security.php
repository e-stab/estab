<?php

declare(strict_types=1);

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

final class OfficialMessageFormHelpFixture
{
    use EstabOfficialMessageFormView;

    /** @var array<string,string> */
    public array $formdata = [];

    /** @var array<string,bool> */
    public array $errorselect = [];

    /** @var array<int,bool> */
    public array $feld = [9 => true, 16 => true];

    /** @var array<int,array<int,array<string,mixed>>> */
    public array $empfarray = [];

    /** @var array<string,array<string,mixed>> */
    public array $attachmentPreviews = [];

    /** @var list<array<string,mixed>> */
    public array $activeTelecomRoutes = [];

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

$fixture = new OfficialMessageFormHelpFixture();
$fixture->formdata = [
    'estab_ttb_lfd' => '142',
    '04_nummer' => '9142',
];
$assert(
    $fixture->official_message_ttb_evidence_text() === '142',
    'The official form does not prefer the incident-local TBB evidence number'
);
$fixture->formdata = ['04_nummer' => '9142'];
$assert(
    $fixture->official_message_ttb_evidence_text()
        === 'noch kein TBB-Nachweis',
    'The official form disguises the technical message number as TBB evidence'
);
$fixture->formdata = ['estab_ttb_lfd' => '0', '04_nummer' => '9142'];
$assert(
    $fixture->official_message_ttb_evidence_text()
        === 'noch kein TBB-Nachweis',
    'The official form invents a TBB evidence number from an invalid link'
);
$fixture->formdata = [];
$definitions = $fixture->official_message_help_definitions();
$assert(
    array_keys($definitions) === range(1, 20),
    'The official filling guide is not represented by exactly 20 ordered hints'
);

$fixture->formdata = ['14_funktion' => 'A/W'];
ob_start();
$fixture->official_message_text_input(
    '14_funktion',
    false,
    128,
    'Funktion des Verfassers'
);
$functionField = (string) ob_get_clean();
$assert(
    str_contains($functionField, 'value="A/W"')
        && str_contains($functionField, '>Fernmelder</span>'),
    'The message form does not separate the persisted function key from its label'
);
ob_start();
$fixture->official_message_recipient_control([
    'display' => 'A/W',
    'function' => 'A/W',
    'copies' => [],
], true);
$recipientField = (string) ob_get_clean();
$assert(
    str_contains($recipientField, '<span>Fernmelder</span>')
        && !str_contains($recipientField, '<span>A/W</span>'),
    'The recipient matrix does not use the Fernmelder display label'
);
$fixture->formdata = [];

$fixture->formdata = [
    '12_anhang' => 'EL0001.pdf;EL0002.jpg;EL0004.eml;EL0001.pdf;../secret.pdf;'
        . 'EL0003.svg;<b>.pdf',
];
$assert(
    $fixture->official_message_attachment_references()
        === ['EL0001.pdf', 'EL0002.jpg', 'EL0004.eml'],
    'Attachment references are not safely validated, ordered and deduplicated'
);
$fixture->task = 'Stab_schreiben';
$assert(
    $fixture->official_message_attachments_editable(),
    'An attachment-origin message task lost its integrated upload controls'
);
$fixture->task = 'Stab_lesen';
$assert(
    !$fixture->official_message_attachments_editable(),
    'A read-only message task can mutate attachments'
);
$assert(
    $fixture->official_message_attachment_size(1536) === '1,5 KiB'
        && $fixture->official_message_attachment_size('1048576') === '1,0 MiB'
        && $fixture->official_message_attachment_size(-1) === '',
    'Attachment sizes are not formatted safely for the card metadata'
);
$assert(
    $fixture->official_message_attachment_date('2026-08-01 20:15:00')
        === '01.08.2026 20:15 Uhr'
        && $fixture->official_message_attachment_date('not-a-date') === '',
    'Attachment timestamps are not rendered as validated German dates'
);

$requiredGuideContent = [
    1 => ['tatsächlich', 'TK-Mittel', 'Funk', 'Kurier/Melder'],
    2 => ['Datum mindestens zweistellig', 'Quittungszeit', 'Namenszeichen'],
    3 => ['Fm-Zentrale', 'Uhrzeit', 'Namenszeichen'],
    4 => ['Datum mindestens zweistellig', 'Gegenstelle', 'Melder', 'Namenszeichen'],
    5 => ['Technischen Betriebsbuch', 'Eingang', 'Ausgang'],
    6 => ['Funkrufnamen', 'Gegenstelle'],
    7 => ['Hinweis', 'TK-Mittel'],
    8 => ['DURCHSAGE', 'Spruch (Ausnahme)'],
    9 => ['Vorrangstufe', 'Sofort', 'Blitz', 'Staatsnot'],
    10 => ['Immer ausfüllen', 'Dienststellen-', 'Eigennamen'],
    11 => ['Rufnummer', 'Gesprächsnotizen'],
    12 => ['eigenständig', 'übermittelt', 'aufgenommen', 'notiert'],
    13 => ['Inhalt ist immer auszufüllen', 'Betreff'],
    14 => ['Inhalt ist immer auszufüllen', 'so kurz wie möglich', 'Blockschrift', 'Nachrichtentext'],
    15 => ['Immer ausfüllen', 'Absender', 'Eigennamen'],
    16 => ['Immer ausfüllen', 'mindestens vierstellig', 'Abfassungszeit'],
    17 => ['Immer ausfüllen', 'Namenszeichen', 'Funktion'],
    18 => ['Sichter', 'vierstelliger Uhrzeit', 'Namenszeichen'],
    19 => ['Verteiler', 'Stab'],
    20 => ['Bearbeitungsvermerke', 'Anlagennummern'],
];
foreach ($requiredGuideContent as $number => $needles) {
    $text = $definitions[$number]['title'] . ' ' . $definitions[$number]['text'];
    foreach ($needles as $needle) {
        $assert(
            str_contains($text, $needle),
            'Filling hint ' . $number . ' omits required guide content: ' . $needle
        );
    }
}

ob_start();
foreach (range(1, 20) as $number) {
    $fixture->official_message_help($number);
}
$helpMarkup = (string) ob_get_clean();

$assert(
    substr_count($helpMarkup, 'data-estab-form-help="') === 20
        && substr_count($helpMarkup, 'role="dialog"') === 20
        && substr_count($helpMarkup, 'role="dialog" tabindex="-1"') === 20
        && substr_count($helpMarkup, 'data-estab-form-help-close="') === 20,
    'The filling guide does not render one accessible control and dialog per field'
);
foreach (range(1, 20) as $number) {
    $assert(
        substr_count($helpMarkup, 'id="estab-form-help-button-' . $number . '"') === 1
            && substr_count($helpMarkup, 'id="estab-form-help-' . $number . '"') === 1
            && str_contains(
                $helpMarkup,
                'aria-controls="estab-form-help-' . $number . '"'
            )
            && str_contains(
                $helpMarkup,
                'aria-describedby="estab-form-help-' . $number . '-description"'
            ),
        'Filling hint ' . $number . ' has ambiguous or incomplete ARIA bindings'
    );
}

$fixture->formdata = ['01_medium' => 'Me'];
ob_start();
$fixture->official_message_radio_group(
    '01_medium',
    $fixture->official_message_medium_options('01_medium'),
    true,
    'Tatsächlich verwendetes Übermittlungsmittel',
    true,
    false,
    false
);
$inactiveMediumMarkup = (string) ob_get_clean();
$assert(
    substr_count($inactiveMediumMarkup, 'name="01_medium"') === 5
        && substr_count($inactiveMediumMarkup, ' disabled') === 5
        && str_contains($inactiveMediumMarkup, 'aria-disabled="true"')
        && !str_contains($inactiveMediumMarkup, ' required')
        && str_contains(
            $inactiveMediumMarkup,
            'id="f_01_medium_me" name="01_medium" value="Me" '
                . 'type="radio" checked="checked"'
        ),
    'The initial unselected conversation-note form does not retain one disabled five-option medium group'
);
ob_start();
$fixture->official_message_radio_group(
    '01_medium',
    $fixture->official_message_medium_options('01_medium'),
    true,
    'Tatsächlich verwendetes Übermittlungsmittel',
    true,
    true,
    true
);
$mediumMarkup = (string) ob_get_clean();
$expectedMediumControls = [
    'fu' => ['Fu', 'Funk'],
    'fe' => ['Fe', 'Telefon'],
    'fax' => ['FAX', 'Telefax'],
    'at' => ['@', 'DFÜ'],
    'me' => ['Me', 'Kurier/Melder'],
];
$assert(
    substr_count($mediumMarkup, 'name="01_medium"') === 5
        && !str_contains($mediumMarkup, ' disabled')
        && substr_count($mediumMarkup, ' required') === 5
        && str_contains($mediumMarkup, 'aria-required="true"'),
    'The active conversation-note transport field is not one enabled and required five-option group'
);
foreach ($expectedMediumControls as $id => [$value, $label]) {
    $expectedControl = 'id="f_01_medium_' . $id
        . '" name="01_medium" value="' . $value . '" type="radio"';
    $assert(
        str_contains($mediumMarkup, $expectedControl)
            && preg_match(
                '/' . preg_quote($expectedControl, '/') . '[^>]*>'
                    . '<span>' . preg_quote($label, '/') . '<\/span>/',
                $mediumMarkup,
            ) === 1
            && ($value !== 'Me' || preg_match(
                '/id="f_01_medium_me"[^>]*checked="checked"/',
                $mediumMarkup
            ) === 1),
        'The official transport choice is missing or mislabeled: ' . $label
    );
}

$fixture->feld[9] = true;
$fixture->formdata = ['09_vorrangstufe' => 'aaa'];
ob_start();
echo '<div class="estab-official-priority">';
$fixture->official_message_priority();
echo '</div>';
$priorityMarkup = (string) ob_get_clean();
$assert(
    str_contains(
        $priorityMarkup,
        'class="estab-official-priority"'
    )
        && str_contains(
            $priorityMarkup,
            'id="f_09_vorrangstufe_sofort"'
        )
        && str_contains(
            $priorityMarkup,
            'id="f_09_vorrangstufe_blitz"'
        )
        && str_contains(
            $priorityMarkup,
            'id="f_09_vorrangstufe_staatsnot"'
        )
        && substr_count(
            $priorityMarkup,
            'id="f_09_vorrangstufe_staatsnot"'
        ) === 1
        && str_contains(
            $priorityMarkup,
            'aria-describedby="estab-form-help-9-description"'
        )
        && preg_match(
            '/id="f_09_vorrangstufe_staatsnot"[^>]*'
                . 'name="09_vorrangstufe"[^>]*value="aaa"[^>]*checked/',
            $priorityMarkup
        ) === 1
        && str_contains($priorityMarkup, 'Staatsnot')
        && str_contains($priorityMarkup, 'ausdrückliche Weisung'),
    'Staatsnot is not selected and explained inside the official priority field'
);
$assert(
    !str_contains($priorityMarkup, 'estab-message-priority-extension')
        && !str_contains($priorityMarkup, 'estab-message-legacy-transport'),
    'The official priority field includes an external workflow control'
);

$fixture->formdata = ['09_vorrangstufe' => 'eee'];
ob_start();
$fixture->official_message_priority();
$historicNoPriorityMarkup = (string) ob_get_clean();
$assert(
    preg_match(
        '/class="estab-official-priority-clear"[^>]*'
            . 'for="f_09_vorrangstufe_keine".*?'
            . 'id="f_09_vorrangstufe_keine"[^>]*'
            . 'name="09_vorrangstufe"[^>]*value="eee"[^>]*checked/s',
        $historicNoPriorityMarkup
    ) === 1
        && str_contains(
            $historicNoPriorityMarkup,
            'id="f_09_vorrangstufe_staatsnot"'
        ),
    'The compact reset option cannot retain the historic no-priority value'
);

$fixture->feld[9] = false;
$fixture->formdata = ['09_vorrangstufe' => 'aaa'];
ob_start();
$fixture->official_message_priority();
$readonlyPriorityMarkup = (string) ob_get_clean();
$assert(
    substr_count(
        $readonlyPriorityMarkup,
        'name="09_vorrangstufe"'
    ) === 1
        && str_contains(
            $readonlyPriorityMarkup,
            'name="09_vorrangstufe" value="aaa"'
        )
        && preg_match(
            '/id="f_09_vorrangstufe_staatsnot"[^>]*'
                . 'value="aaa"[^>]*disabled[^>]*checked/',
            $readonlyPriorityMarkup
        ) === 1,
    'A stored Staatsnot mark is not retained in the read-only official field'
);

$fixture->feld[9] = true;
$fixture->feld[6] = false;
$fixture->formdata = [
    '06_befweg' => '',
    '06_befwegausw' => '',
    '08_befhinweis' => 'Historischer nicht sichtbarer Wert',
    '08_befhinwausw' => 'Fu',
    '09_vorrangstufe' => 'aaa',
];
$fixture->task = 'Stab_schreiben';
ob_start();
$fixture->official_message_workflow_controls();
$workflowMarkup = (string) ob_get_clean();
$assert(
    !str_contains($workflowMarkup, 'estab-message-priority-extension')
        && !str_contains($workflowMarkup, 'estab-message-legacy-transport')
        && !str_contains($workflowMarkup, 'Zusätzlicher Beförderungshinweis')
        && !str_contains($workflowMarkup, 'Vorrangstufe ergänzen')
        && !str_contains($workflowMarkup, 'Historischer nicht sichtbarer Wert')
        && !str_contains($workflowMarkup, 'id="f_08_befhinweis"')
        && !str_contains($workflowMarkup, 'id="f_08_befhinwausw_')
        && !str_contains($workflowMarkup, 'name="09_vorrangstufe"'),
    'Workflow additions still duplicate priority or expose a transport hint'
);
$fixture->formdata = [];
$fixture->task = 'Stab_schreiben';

$fixture->formdata = [
    '01_datum' => '311845Jul2026',
    '01_zeichen' => 'aw',
    '02_zeit' => '1846',
    '02_zeichen' => 'fm',
    '03_datum' => '311847Jul2026',
    '03_zeichen' => 'fm',
];
ob_start();
foreach ([
    ['Aufnahmevermerk', 1, 2, '01_datum', '01_zeichen'],
    ['Annahmevermerk', 2, 3, '02_zeit', '02_zeichen'],
    ['Beförderungsvermerk', 3, 4, '03_datum', '03_zeichen'],
] as [$title, $printedNumber, $helpNumber, $timeField, $markField]) {
    $fixture->official_message_timestamp_block(
        $title,
        $printedNumber,
        $helpNumber,
        $timeField,
        $markField,
        true,
        'Datum und Uhrzeit',
        'Namenszeichen'
    );
}
$stampMarkup = (string) ob_get_clean();
$assert(
    substr_count($stampMarkup, 'data-estab-stamp-cell="datum"') === 3
        && substr_count($stampMarkup, 'data-estab-stamp-cell="uhrzeit"') === 3
        && substr_count($stampMarkup, 'data-estab-stamp-cell="hdz"') === 3,
    'The three official stamps do not expose separate Datum/Uhrzeit/Hdz cells'
);
foreach (['01_datum', '02_zeit', '03_datum'] as $timeField) {
    $assert(
        substr_count($stampMarkup, 'name="' . $timeField . '"') === 1
            && str_contains(
                $stampMarkup,
                'data-estab-single-backend-field="' . $timeField . '"'
            )
            && str_contains(
                $stampMarkup,
                'aria-describedby="estab-stamp-description-' . $timeField . '"'
            )
            && !str_contains($stampMarkup, 'name="' . $timeField . '_datum"')
            && !str_contains($stampMarkup, 'name="' . $timeField . '_uhrzeit"'),
        'Stamp ' . $timeField . ' no longer binds two visual cells to one accessible field'
    );
}
$assert(
    substr_count($stampMarkup, 'data-estab-stamp-time-only="true"') === 1
        && str_contains(
            $stampMarkup,
            'data-estab-single-backend-field="02_zeit" role="group" '
                . 'data-estab-stamp-time-only="true"'
        )
        && preg_match(
            '/data-estab-single-backend-field="02_zeit".*?'
                . 'data-estab-stamp-value="date"><\/span>.*?'
                . 'data-estab-stamp-value="time">1846<\/span>/s',
            $stampMarkup
        ) === 1
        && str_contains(
            $stampMarkup,
            'data-estab-stamp-value="date">31Jul2026</span>'
        )
        && str_contains(
            $stampMarkup,
            'data-estab-stamp-value="time">1845</span>'
        ),
    'A time-only stamp value is not shown exclusively in the Uhrzeit cell'
);

$fixture->empfarray = [
    1 => [
        1 => ['fkt' => 'LS', 'rolle' => '', 'checked' => true, 'cpycol' => 'bl'],
        2 => ['fkt' => 'S5', 'rolle' => '', 'checked' => true, 'cpycol' => 'ge'],
        3 => ['fkt' => 'S1', 'rolle' => '', 'checked' => false, 'cpycol' => ''],
        4 => ['fkt' => 'POL', 'rolle' => 'FB', 'checked' => false, 'cpycol' => ''],
    ],
    2 => [
        1 => ['fkt' => 'S6', 'rolle' => '', 'checked' => false, 'cpycol' => ''],
        2 => ['fkt' => 'S2', 'rolle' => '', 'checked' => true, 'cpycol' => 'rt'],
        3 => ['fkt' => 'S3', 'rolle' => '', 'checked' => false, 'cpycol' => ''],
        4 => ['fkt' => 'VB Feuerwehr', 'rolle' => '', 'checked' => false, 'cpycol' => ''],
    ],
    3 => [
        1 => ['fkt' => 'S4', 'rolle' => '', 'checked' => false, 'cpycol' => ''],
        2 => ['fkt' => 'AB_C', 'rolle' => '', 'checked' => true, 'cpycol' => 'gn'],
        3 => ['fkt' => 'A/W', 'rolle' => 'Fernmelder', 'checked' => false, 'cpycol' => ''],
    ],
];
$fixture->task = 'FM-Admin';
$fixture->formdata = [
    '04_richtung' => 'E',
    '16_empf' => 'LS_bl,S1_bl,S2_rt,S5_ge,AB_C_bl,AB_C_gn,A/W_bl,',
];
$distributionModel = $fixture->official_message_distribution_model();
$assert(
    array_map(
        static fn(array $entry): string => (string) $entry['display'],
        $distributionModel['groups']['lead']
    ) === ['Leiter', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6']
        && count($distributionModel['groups']['adviser']) === 6
        && count($distributionModel['groups']['liaison']) === 6,
    'The official distributor does not expose its fixed Leiter/S1-S6 and six-row layout'
);
$underscoreExtra = array_values(array_filter(
    $distributionModel['extras'],
    static fn(array $entry): bool => ($entry['function'] ?? '') === 'AB_C'
));
$assert(
    count($underscoreExtra) === 1
        && $underscoreExtra[0]['copies'] === ['bl', 'gn'],
    'A function containing an underscore loses one of its selected copies'
);
ob_start();
$fixture->official_message_distribution();
$readonlyDistribution = (string) ob_get_clean();
ob_start();
$fixture->official_message_extra_distribution();
$readonlyExtras = (string) ob_get_clean();
$officialRecipientOrder = ['Leiter', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6'];
$previousRecipientOffset = -1;
foreach ($officialRecipientOrder as $recipient) {
    $recipientOffset = strpos(
        $readonlyDistribution,
        '<span>' . $recipient . '</span>',
        $previousRecipientOffset + 1
    );
    $assert(
        $recipientOffset !== false && $recipientOffset > $previousRecipientOffset,
        'Official lead recipient order is wrong at ' . $recipient
    );
    $previousRecipientOffset = $recipientOffset;
}
$assert(
    !str_contains($readonlyDistribution, '<span>AB_C</span>')
        && str_contains($readonlyExtras, '<span>AB_C</span>')
        && substr_count(
            $readonlyDistribution,
            'class="estab-official-box-choice estab-official-copy-indicator"'
        ) === 9
        && str_contains(
            $readonlyDistribution,
            'aria-label="Leiter, blaue Durchschrift ausgewählt, '
                . 'schreibgeschützt"'
        )
        && str_contains(
            $readonlyDistribution,
            'aria-label="S2, rote Durchschrift ausgewählt, '
                . 'schreibgeschützt"'
        )
        && str_contains(
            $readonlyDistribution,
            'aria-label="S5, gelbe Durchschrift ausgewählt, '
                . 'schreibgeschützt"'
        )
        && str_contains(
            $readonlyExtras,
            'aria-label="AB_C, blaue und grüne Durchschrift ausgewählt, '
                . 'schreibgeschützt"'
        ),
    'Read-only official and dynamic recipients lose labels, copies or separation'
);

$historicalFixture = new OfficialMessageFormHelpFixture();
$historicalFixture->task = 'FM-Admin';
$historicalFixture->empfarray = [
    1 => [
        1 => ['fkt' => 'LS', 'rolle' => '', 'checked' => false, 'cpycol' => ''],
    ],
];
$historicalFixture->formdata = [
    '04_richtung' => 'E',
    '16_empf' => 'S1_bl,AB_C_bl,AB_C_gn,',
];
$historicalModel = $historicalFixture->official_message_distribution_model();
$historicalS1 = array_values(array_filter(
    $historicalModel['groups']['lead'],
    static fn(array $entry): bool => ($entry['display'] ?? '') === 'S1'
));
$historicalUnderscoreExtra = array_values(array_filter(
    $historicalModel['extras'],
    static fn(array $entry): bool => ($entry['function'] ?? '') === 'AB_C'
));
$assert(
    count($historicalS1) === 1
        && $historicalS1[0]['function'] === 'S1'
        && $historicalS1[0]['copies'] === ['bl']
        && ($historicalS1[0]['unavailable'] ?? false) === true
        && count($historicalUnderscoreExtra) === 1
        && $historicalUnderscoreExtra[0]['copies'] === ['bl', 'gn']
        && ($historicalUnderscoreExtra[0]['historical'] ?? false) === true
        && ($historicalUnderscoreExtra[0]['unavailable'] ?? false) === true,
    'Recipients removed from the live matrix lose their historical copy record'
);
ob_start();
$historicalFixture->official_message_distribution();
$historicalDistribution = (string) ob_get_clean();
ob_start();
$historicalFixture->official_message_extra_distribution();
$historicalExtras = (string) ob_get_clean();
$assert(
    str_contains(
        $historicalDistribution,
        'aria-label="S1, blaue Durchschrift ausgewählt, '
            . 'schreibgeschützt"'
    )
        && str_contains(
            $historicalExtras,
            'aria-label="AB_C, blaue und grüne Durchschrift ausgewählt, '
                . 'schreibgeschützt"'
        )
        && substr_count(
            $historicalDistribution . $historicalExtras,
            'data-estab-recipient-unavailable="true"'
        ) >= 2,
    'Historical recipients are not visibly retained in the read-only record'
);
$assert(
    !str_contains($historicalDistribution . $historicalExtras, 'Dokumentiert:')
        && !str_contains($historicalDistribution . $historicalExtras, 'S1_bl')
        && !str_contains($historicalDistribution . $historicalExtras, 'AB_C_bl')
        && !str_contains($historicalDistribution . $historicalExtras, 'AB_C_gn'),
    'The official read-only grid exposes raw recipient storage tokens'
);

$fixture->task = 'Stab_sichten';
ob_start();
$fixture->official_message_distribution();
$editableDistribution = (string) ob_get_clean();
ob_start();
$fixture->official_message_extra_distribution();
$editableExtras = (string) ob_get_clean();
$assert(
    str_contains($editableDistribution, 'name="16_12" value="16_12_bl"')
        && str_contains($editableDistribution, 'name="16_13" value="16_13_bl"')
        && str_contains($editableExtras, 'name="16_32" value="16_32_bl"')
        && str_contains(
            $editableDistribution,
            'aria-label="S5 als Empfänger auswählen"'
        )
        && str_contains(
            $editableExtras,
            'aria-label="AB_C als Empfänger auswählen"'
        )
        && !str_contains(
            $editableDistribution . $editableExtras,
            'name="16_gncopy"'
        )
        && !str_contains(
            $editableDistribution . $editableExtras,
            'estab-message-green-copy'
        )
        && !str_contains(
            $editableDistribution . $editableExtras,
            'Grüne Durchschrift'
        ),
    'Editable distribution still exposes a second copy decision outside the recipient checkboxes'
);

$fixture->feld[16] = false;
ob_start();
$fixture->official_message_extra_distribution();
$staffDraftExtras = (string) ob_get_clean();
$assert(
    str_contains(
        $staffDraftExtras,
        'aria-label="AB_C, blaue und grüne Durchschrift ausgewählt, '
            . 'schreibgeschützt"'
    )
        && !str_contains(
            $staffDraftExtras,
            'name="16_32" value="16_32_bl"'
        )
        && !str_contains($staffDraftExtras, 'name="16_gncopy"'),
    'A staff draft can edit the Si-controlled final recipient distribution'
);
$fixture->feld[16] = true;

$fixture->task = 'Stab_gesprnoti';
ob_start();
$fixture->official_message_extra_distribution();
$conversationDistribution = (string) ob_get_clean();
$assert(
    str_contains(
        $conversationDistribution,
        'name="16_32" value="16_32_bl"'
    )
        && !str_contains($conversationDistribution, 'name="16_gncopy"')
        && !str_contains(
            $conversationDistribution,
            'class="estab-message-green-copy"'
        ),
    'A conversation note still offers a second recipient-copy selection'
);

$view = file_get_contents($root . '/4fach/official_message_form.php');
$controller = file_get_contents($root . '/4fach/4fachform.php');
$css = file_get_contents($root . '/estab-ui.css');
$dockerfile = file_get_contents($root . '/Dockerfile');
$assert(
    is_string($view)
        && is_string($controller)
        && is_string($css)
        && is_string($dockerfile),
    'Official form implementation files are not readable'
);
$assert(
    str_contains($view, 'data-estab-conversation-medium')
        && str_contains($view, 'data-estab-conversation-medium-status')
        && str_contains(
            $view,
            'document.getElementById("f_11_gesprnotiz")'
        ),
    'The initial staff form lacks the stable conversation-medium toggle contract'
);
$conversationAccessStart = strpos(
    $controller,
    'case "Stab_gesprnoti":'
);
$conversationAccessEnd = strpos(
    $controller,
    'case "FM-Admin"',
    $conversationAccessStart === false ? 0 : $conversationAccessStart
);
$conversationAccess = (
    is_int($conversationAccessStart)
    && is_int($conversationAccessEnd)
    && $conversationAccessEnd > $conversationAccessStart
) ? substr(
    $controller,
    $conversationAccessStart,
    $conversationAccessEnd - $conversationAccessStart
) : '';
$assert(
    $conversationAccess !== ''
        && preg_match(
            '/\$this->feld\s*\[1\]\s*=\s*true\s*;/',
            $conversationAccess
        ) === 1,
    'The dedicated conversation-note task does not enable transport field 1 server-side'
);
$assert(
    str_contains($view, 'official_message_ttb_evidence_text()')
        && str_contains($view, "return 'noch kein TBB-Nachweis';")
        && str_contains(
            $view,
            "\$value = \$this->formdata['estab_ttb_lfd'] ?? null;"
        )
        && !str_contains(
            $view,
            ": (string) (\$this->formdata['04_nummer'] ?? '')"
        )
        && str_contains(
            $view,
            "\$this->safe_message_value('04_nummer')"
        ),
    'The official form does not separate visible TBB evidence from its hidden technical message value'
);

$officialLabels = [
    'Fm-Zentrale',
    'Technisches<br>Betriebsbuch',
    'Aufnahmevermerk',
    'Annahmevermerk',
    'Beförderungsvermerk',
    'Rufname der Gegenstelle',
    'DURCHSAGE',
    'GESPRÄCHS-<br>NOTIZ',
    'Absender:',
    'Abfassungszeit:',
    'Einheit/Einrichtung/Stelle',
    'Sichter',
    'Vermerke:',
];
$renderStart = strpos($view, 'function plot_official_message_form');
$assert(
    $renderStart !== false,
    'Official form render method is missing'
);
$renderView = substr($view, (int) $renderStart);
$assert(
    !str_contains($renderView, 'Dokumentiert:'),
    'The official form source still renders a raw recipient-token line'
);
$assert(
    !str_contains($view, 'name="16_gncopy"')
        && !str_contains($view, 'estab-message-green-copy')
        && !str_contains($controller, 'name=\"16_gncopy\"'),
    'The live or retained form renderer still contains an external green-copy control'
);
$previousOffset = -1;
foreach ($officialLabels as $label) {
    $offset = strpos($renderView, $label);
    $assert(
        $offset !== false && $offset > $previousOffset,
        'Official printed label is missing or out of sequence: ' . $label
    );
    $previousOffset = $offset;
}
$assert(
    str_contains($view, "'lead' => 'TEL/EL/EAL/UEAL'")
        && str_contains($view, "'adviser' => 'Fachberater'")
        && str_contains($view, "'liaison' => 'Verb.stellen'"),
    'Official distribution headings are incomplete'
);
$assert(
    str_contains($view, 'data-estab-copy-distribution')
        && str_contains($view, 'Blatt 1 (blau) ')
        && str_contains($view, 'Sachgebiet/Fachber./Verbindungsstelle')
        && str_contains($view, 'Blatt 2 (grün) ')
        && str_contains($view, 'Sachgebiet/Fachber./Verbindungsstelle')
        && str_contains($view, 'Blatt 3 (rot) ')
        && str_contains($view, 'Sachgebiet 2 Lage')
        && str_contains($view, 'Blatt 4 (gelb) ')
        && str_contains($view, 'Techn. Betriebsbuch')
        && substr_count($view, 'data-estab-punch-hole="') === 2,
    'The official copy/distribution legend or its two punch holes is incomplete'
);

$assert(
    str_contains($view, "official_message_text_input(\n            '11_rufnummer'")
        && str_contains($view, "official_message_text_input(\n            '12_betreff'")
        && str_contains($view, "official_message_textarea(\n            '12_inhalt'"),
    'Phone number, subject and message text are not separate official fields'
);
$workflowControlsStart = strpos(
    $view,
    'function official_message_workflow_controls()'
);
$workflowControlsEnd = strpos(
    $view,
    'function official_message_help_script()',
    is_int($workflowControlsStart) ? $workflowControlsStart : 0
);
$workflowControlsView = (
    is_int($workflowControlsStart)
    && is_int($workflowControlsEnd)
    && $workflowControlsEnd > $workflowControlsStart
) ? substr(
    $view,
    $workflowControlsStart,
    $workflowControlsEnd - $workflowControlsStart
) : '';
$assert(
    $workflowControlsView !== ''
        && !str_contains(
            $workflowControlsView,
            'estab-message-priority-extension'
        )
        && !str_contains(
            $workflowControlsView,
            'estab-message-legacy-transport'
        )
        && !str_contains(
            $workflowControlsView,
            'Zusätzlicher Beförderungshinweis'
        )
        && !str_contains($workflowControlsView, 'id="f_08_befhinweis"')
        && !str_contains($workflowControlsView, 'id="f_08_befhinwausw_')
        && !str_contains($workflowControlsView, 'name="09_vorrangstufe"'),
    'Workflow additions duplicate the official priority or expose a transport hint'
);
$assert(
    str_contains(
        $view,
        "file_get_contents(\n            __DIR__ . '/../estab-ui.css'"
    )
        && str_contains(
            $view,
            'Das Stylesheet des Nachrichtenvordrucks ist nicht lesbar.'
        )
        && str_contains($view, '$officialStylesheet'),
    'The standalone legacy form response does not load the shared UI stylesheet'
);
$assert(
    str_contains($view, 'inputmode="tel" autocomplete="tel"')
        && str_contains($view, 'maxlength="')
        && str_contains($view, 'aria-invalid="true"'),
    'Modern form inputs lack safe limits, semantics or validation feedback'
);
$assert(
    str_contains($view, 'data-estab-form-zones="3"')
        && str_contains($view, 'data-estab-form-zone="fm-zentrale"')
        && str_contains($view, 'data-estab-form-zone="nachricht"')
        && str_contains($view, 'data-estab-form-zone="sichter"'),
    'The official three-part form structure is not explicit'
);
$assert(
    str_contains(
        $view,
        '<input type="hidden" name="recipient_matrix_revision" value="'
    )
        && str_contains(
            $view,
            'estab_workflow_recipient_matrix_revision('
        )
        && str_contains($view, '$empf_matrix,')
        && str_contains($view, '(string)$this->redcopy2')
        && strpos($renderView, 'name="recipient_matrix_revision"')
            < strpos($renderView, 'name="kate_todo"'),
    'The form does not submit a server-derived recipient-matrix revision value'
);
$assert(
    str_contains($view, 'estab-official-lead-director')
        && str_contains($view, 'estab-official-lead-sections')
        && str_contains($view, "5 => ['display' => 'S5'")
        && str_contains($css, 'grid-template-rows: repeat(6,'),
    'The official Leiter/S1-S6 distributor geometry can collapse'
);
$actionsStart = strpos($view, 'function official_message_actions');
$actionsEnd = strpos($view, 'function official_message_categories');
$actionsView = $actionsStart !== false && $actionsEnd !== false
    ? substr($view, $actionsStart, $actionsEnd - $actionsStart)
    : '';
$assert(
    $actionsView !== ''
        && !str_contains($actionsView, 'name="anhang_plus_x"'),
    'The separate attachment action still competes with the integrated panel'
);
$assert(
    str_contains($view, 'enctype="multipart/form-data"')
        && str_contains(
            $view,
            '<input id="f_12_anhang" type="hidden" name="12_anhang"'
        )
        && substr_count($renderView, 'id="f_12_anhang"') === 1,
    'The message form cannot safely submit direct uploads or loses its attachment list'
);
$assert(
    str_contains($view, 'id="nachrichtenanlagen"')
        && str_contains($view, '<h2 id="nachrichtenanlagen-title">Anlagen (')
        && str_contains($view, 'class="estab-message-attachment-badge')
        && str_contains($view, "'Anlage hinzufügen'")
        && str_contains($view, 'estab-message-attachment-jump')
        && str_contains($view, 'data-estab-attachment-count="'),
    'The form does not make attached files immediately visible at the message'
);
$assert(
    str_contains($view, 'name="message_attachment_upload"')
        && str_contains($view, 'name="message_attachment_request_token"')
        && str_contains($view, 'data-estab-max-bytes="')
        && str_contains($view, 'name="message_attachment_comment"')
        && str_contains(
            $view,
            'name="message_attachment_upload_x" value="1"'
        )
        && str_contains(
            $view,
            'name="anhang_plus_x" value="1"'
        )
        && str_contains($view, 'estab_attachment_allowed_extensions()')
        && str_contains($view, 'estab_attachment_upload_accept()')
        && str_contains($view, 'estab_attachment_upload_limit_label()'),
    'Integrated upload controls, format guidance or existing-file selection are incomplete'
);
$assert(
    str_contains($view, 'data-estab-attachment-upload-limit')
        && str_contains($view, 'input.files[0].size > maximum')
        && str_contains($view, 'event.submitter')
        && str_contains($view, 'event.preventDefault()')
        && str_contains($view, 'Ihre Eingaben bleiben erhalten'),
    'oversized direct upload can discard the unsaved browser form'
);
$assert(
    str_contains($view, 'name="message_attachment_remove_x" value="')
        && str_contains($view, '>Vom Vordruck entfernen</button>')
        && str_contains($view, "'&view=inline'")
        && str_contains($view, 'showpic.php')
        && str_contains(
            $view,
            '<iframe loading="lazy" '
        )
        && !str_contains($view, '<iframe loading="lazy" sandbox')
        && str_contains($view, 'data-estab-pdf-preview')
        && str_contains($view, 'data-estab-email-preview')
        && str_contains($view, 'data-estab-email-attachment')
        && str_contains($view, '/email.php')
        && str_contains($view, '>E-Mail hier anzeigen</summary>')
        && str_contains($view, 'Originaldatei herunterladen')
        && str_contains($view, 'data-src="')
        && str_contains($view, 'frame.setAttribute("src"')
        && str_contains($view, 'referrerpolicy="no-referrer"')
        && str_contains($view, '<img loading="lazy"'),
    'Attachment removal, download or safe in-browser previews are incomplete'
);
$assert(
    str_contains($view, 'decoding="async"')
        && str_contains($view, 'fetchpriority="low"'),
    'automatic image cards do not defer expensive NAS preview work'
);
$assert(
    str_contains($view, 'data-estab-attachment-feedback')
        && str_contains($view, 'data-estab-attachment-presentation')
        && str_contains($view, 'feedback.focus({ preventScroll: true })')
        && str_contains($view, 'feedback.scrollIntoView({ block: "start" })')
        && str_contains($view, 'data-estab-attachment-unavailable')
        && str_contains($view, 'Anlage derzeit nicht ')
        && str_contains($view, 'in neuem Browser-Tab ansehen')
        && str_contains($view, 'vom Vordruck entfernen'),
    'Attachment feedback, unavailable state, or accessible file actions are missing'
);
$assert(
    str_contains($view, "\$metadata['org_filename']")
        && str_contains($view, "\$metadata['comment']")
        && str_contains($view, "\$metadata['ingest_size']")
        && str_contains($view, "\$metadata['date']")
        && str_contains($view, 'estab_message_html($originalName)')
        && str_contains($view, 'estab_message_html($comment)')
        && str_contains($view, 'estab_message_html($reference)'),
    'Authorized attachment metadata is missing or crosses the HTML boundary unescaped'
);
$assert(
    str_contains($view, "\$this->formdata['estab_attachment_error']")
        && str_contains($view, "\$this->formdata['estab_attachment_notice']")
        && str_contains($view, "\$this->formdata['estab_attachment_comment']")
        && str_contains($view, 'role="alert"')
        && str_contains($view, 'role="status"'),
    'Integrated upload feedback or safe comment rehydration is missing'
);
$assert(
    str_contains($view, 'event.key !== "Escape"')
        && str_contains($view, 'document.addEventListener("pointerdown"')
        && str_contains($view, 'window.addEventListener("resize"')
        && str_contains($view, 'dialog.focus({ preventScroll: true })')
        && str_contains($view, 'close(button, true);')
        && str_contains($view, 'aria-expanded'),
    'Help bubbles are not fully operable by keyboard, pointer and resize'
);
$assert(
    str_contains($view, "\$reviewIdentityBound = \$this->task === 'Stab_sichten'")
        && str_contains($view, 'data-estab-server-timestamp="submit"')
        && str_contains($view, '<strong id="f_15_quitzeichen"')
        && str_contains(
            $view,
            'aria-label="Sichterzeichen wird aus der Anmeldung übernommen"'
        )
        && str_contains($view, "\$editableReceipt = (bool)\$this->feld[15]"),
    'Sighting identity fields are not kept server-authoritative'
);
$assert(
    str_contains($view, "'FM-Ausgang',")
        && str_contains($view, '$editable && $markBound')
        && str_contains(
            $view,
            '$markLabel . \' wird aus der Anmeldung übernommen\''
        ),
    'Authenticated telecommunications marks are not read-only in the UI'
);

$assert(
    str_contains($controller, 'use EstabOfficialMessageFormView;')
        && str_contains($controller, '$this->plot_official_message_form ();'),
    'The operational controller does not use the official renderer'
);
$assert(
    str_contains($controller, '$this->load_attachment_previews ();')
        && str_contains($controller, 'function load_attachment_previews ()')
        && str_contains($controller, 'estab_read_attachments (')
        && str_contains($controller, '"estab_attachment_error"')
        && str_contains($controller, '"estab_attachment_notice"')
        && str_contains($controller, '"estab_attachment_comment"'),
    'Attachment cards bypass object-level authorization or lose upload feedback'
);
$assert(
    str_contains(
        $controller,
        "'/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\$/D'"
    )
        && str_contains(
            $controller,
            'konv_datetime_taktime ($this->formdata [$dateField])'
        ),
    'Database timestamps are not normalized at the shared form-render boundary'
);
$assert(
    str_contains($dockerfile, '4fach/official_message_form.php')
        && str_contains($dockerfile, '4fach/email.php'),
    'The runtime image omits the official renderer or email view'
);
$assert(
    str_contains($css, '--estab-official-blue: #a2d9f7;')
        && str_contains($css, 'grid-template-columns: 5.25rem minmax(0, 1fr)')
        && str_contains($css, 'min-width: 56rem;')
        && str_contains($css, 'overflow: auto;')
        && str_contains($css, '.estab-official-stamp-visual-cell--time')
        && str_contains(
            $css,
            'grid-template-columns: 1fr 1.016fr 1.07fr;'
        )
        && str_contains(
            $css,
            'grid-template-columns: 1fr 1fr 0.65fr;'
        )
        && str_contains($css, 'margin-left: 3.4rem;')
        && str_contains($css, '.estab-official-copy-distribution')
        && str_contains($css, '.estab-official-punch-hole--lower')
        && str_contains($css, '.estab-official-zone--content::before')
        && str_contains(
            $css,
            ".estab-official-address-value {\n"
                . "    grid-column: 2;\n    grid-row: 1;"
        )
        && str_contains($css, '.estab-message-distribution-extras')
        && !str_contains($css, '.estab-message-green-copy')
        && str_contains($css, '.estab-message-attachments')
        && str_contains($css, '.estab-message-attachment-upload-grid')
        && str_contains($css, '.estab-message-attachment-card')
        && str_contains(
            $css,
            '.estab-message-attachment-card[data-estab-attachment-unavailable]'
        )
        && str_contains($css, '.estab-message-attachment-pdf iframe')
        && str_contains($css, '.estab-message-attachment-email iframe')
        && str_contains($css, '.estab-email-preview-page'),
    'The official colour, strict sheet geometry or non-reflowing mobile sheet regressed'
);
$assert(
    str_contains(
        $css,
        'grid-template-columns: repeat(10, minmax(0, 1fr));'
    )
        && str_contains($css, 'grid-column: 9 / 11;')
        && str_contains(
            $css,
            'grid-template-columns: 33% minmax(0, 1fr);'
        )
        && str_contains(
            $css,
            'grid-template-columns: 19.8% 60.3% 19.9%;'
        )
        && str_contains(
            $css,
            'grid-template-columns: 19.8% 40.9% 39.3%;'
        ),
    'Reference-bound TTB, callsign, address or composition proportions regressed'
);
$assert(
    preg_match(
        '/\.estab-official-ttb\s*>\s*\.estab-official-input,\s*'
            . '\.estab-official-ttb\s*>\s*\.estab-official-readonly\s*\{'
            . '[^}]*border:\s*1\.5px\s+solid\s+'
            . 'var\(--estab-official-line\);[^}]*'
            . 'box-sizing:\s*border-box;[^}]*'
            . 'min-height:\s*2\.2rem;/s',
        $css
    ) === 1,
    'The editable or read-only TTB number field lost its official border'
);
$assert(
    str_contains($css, '@media (max-width: 56rem)')
        && str_contains(
            $css,
            'content: "Zum vollständigen Vordruck horizontal wischen"'
        )
        && str_contains($css, '@media print')
        && str_contains($css, 'print-color-adjust: exact')
        && str_contains($css, 'content: none !important;')
        && str_contains($css, 'break-inside: avoid-page;')
        && str_contains($css, 'zoom: 0.78;')
        && str_contains($css, 'height: 29.7rem;')
        && str_contains($css, 'grid-template-rows: 4.2rem minmax(8.8rem, 1fr);'),
    'Responsive orientation or print fidelity is missing'
);
$assert(
    str_contains($css, '.estab-official-help-button:focus-visible')
        && str_contains($css, '.estab-official-input:focus')
        && str_contains($css, '.estab-official-help-dialog[hidden]'),
    'Interactive official-form controls lack visible focus or hidden-state styling'
);
$assert(
    str_contains(
        $css,
        ".estab-message-attachment-list,\n"
            . "    .estab-message-attachments,"
    )
        && str_contains($css, '@media (max-width: 34rem)')
        && str_contains(
            $css,
            ".estab-message-attachment-card {\n"
                . "        grid-template-columns: 1fr;"
        ),
    'The integrated attachment panel is not print-safe or responsive'
);

echo 'Official message form UI security: OK (' . $assertions
    . " assertions)\n";
