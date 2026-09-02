<?php

declare(strict_types=1);

/**
 * Feld 7 des Nachrichtenvordrucks: das gewünschte Übermittlungsmittel.
 *
 * Wer eine ausgehende Nachricht verfasst, äußert hier den Wunsch, über welches
 * TK-Mittel sie befördert werden soll. Der tatsächlich benutzte Weg gehört in
 * Feld 1; LdF disponiert ihn später aus dem gültigen S6-Fernmeldeplan. Ein
 * Vordruck, in dem Feld 7 in keinem Arbeitsschritt beschreibbar ist, nimmt den
 * Wunsch nie auf.
 */

$root = dirname(__DIR__, 2);

require_once $root . '/app/dv_rules.php';
require_once $root . '/app/message_transport.php';
require_once $root . '/app/file_access.php';
require_once $root . '/app/attachment.php';
require_once $root . '/app/attachment_upload.php';
require_once $root . '/4fach/official_message_form.php';
require_once $root . '/4fach/vali_data.php';

$previousDirectory = getcwd();
if (!chdir($root . '/4fach')) {
    throw new RuntimeException(
        'Could not enter the message controller directory'
    );
}
try {
    require_once $root . '/4fach/data_hndl.php';
} finally {
    if (is_string($previousDirectory)) {
        chdir($previousDirectory);
    }
}

final class EstabDesiredMediumFormFixture
{
    use EstabOfficialMessageFormView;

    /** @var array<string,mixed> */
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

// 1. The form knows a rule for who may state the wish at all.
$assert(
    function_exists('estab_message_desired_medium_editable'),
    estab_dv_requirement(
        'NV-07-TKM-WUNSCH',
        'Kein Arbeitsschritt ist als Verfasser von Feld 7 benannt'
    )
);

// 2. The workflow steps in which the author formulates the wish.
foreach (['Stab_schreiben', 'Stab_korrigieren'] as $authoringTask) {
    $assert(
        estab_message_desired_medium_editable($authoringTask),
        estab_dv_requirement(
            'NV-07-TKM-WUNSCH',
            'Arbeitsschritt ' . $authoringTask
                . ' kann das gewünschte TK-Mittel nicht eintragen'
        )
    );
}

// 3. Every later step documents evidence and must not rewrite the wish.
foreach ([
    'FM-Eingang',
    'FM-Eingang_Anhang',
    'LdF-Eingang',
    'LdF-Ausgang',
    'FM-Ausgang',
    'Stab_sichten',
    // A conversation note documents an own call. estab_workflow_route_allowed()
    // rejects the whole request as soon as it carries a transport medium, so a
    // writable Feld 7 would silently deny every note.
    'Stab_gesprnoti',
    'Stab_lesen',
    'FM-Admin',
    'SI-Admin',
] as $laterTask) {
    $assert(
        !estab_message_desired_medium_editable($laterTask),
        estab_dv_requirement(
            'NV-07-TKM-WUNSCH',
            'Arbeitsschritt ' . $laterTask
                . ' darf den Wunsch in Feld 7 nachträglich ändern'
        )
    );
}

// 4. The rendered field really carries the writable radio group.
$fixture = new EstabDesiredMediumFormFixture();
$fixture->formdata = ['06_befwegausw' => 'Fu'];
$fixture->task = 'Stab_schreiben';
ob_start();
$fixture->official_message_radio_group(
    '06_befwegausw',
    $fixture->official_message_medium_options('06_befwegausw'),
    estab_message_desired_medium_editable($fixture->task),
    'Gewünschtes Übermittlungsmittel',
    $fixture->task !== 'LdF-Ausgang'
);
$authoringMarkup = (string) ob_get_clean();
$assert(
    substr_count($authoringMarkup, 'name="06_befwegausw"') === 5
        && !str_contains($authoringMarkup, ' disabled')
        && !str_contains($authoringMarkup, 'aria-disabled="true"')
        && !str_contains($authoringMarkup, 'type="hidden"')
        && str_contains(
            $authoringMarkup,
            'id="f_06_befwegausw_fu" name="06_befwegausw" value="Fu" '
                . 'type="radio" checked="checked"'
        ),
    estab_dv_requirement(
        'NV-07-TKM-WUNSCH',
        'Der Vordruck zeigt beim Verfassen keine ausfüllbare Auswahl für Feld 7'
    )
);

$fixture->task = 'LdF-Ausgang';
ob_start();
$fixture->official_message_radio_group(
    '06_befwegausw',
    $fixture->official_message_medium_options('06_befwegausw'),
    estab_message_desired_medium_editable($fixture->task),
    'Gewünschtes Übermittlungsmittel',
    $fixture->task !== 'LdF-Ausgang'
);
$leadMarkup = (string) ob_get_clean();
$assert(
    !str_contains($leadMarkup, 'name="06_befwegausw"')
        && substr_count($leadMarkup, ' disabled') === 5
        && str_contains($leadMarkup, 'aria-disabled="true"'),
    estab_dv_requirement(
        'NV-07-TKM-WUNSCH',
        'LdF kann den Wunsch in Feld 7 über den Vordruck überschreiben'
    )
);

// 5. The printed grid decides exactly along this rule.
$formSource = file_get_contents($root . '/4fach/official_message_form.php');
$assert(
    is_string($formSource),
    estab_dv_requirement(
        'NV-07-TKM-WUNSCH',
        'Der Nachrichtenvordruck ist nicht lesbar'
    )
);
$formSource = (string) $formSource;
$assert(
    preg_match(
        '/official_message_radio_group\(\s*\'06_befwegausw\',\s*'
            . '\$this->official_message_medium_options\('
            . '\'06_befwegausw\'\),\s*'
            . 'estab_message_desired_medium_editable\(\$this->task\),/',
        $formSource
    ) === 1,
    estab_dv_requirement(
        'NV-07-TKM-WUNSCH',
        'Feld 7 entscheidet seine Beschreibbarkeit nicht am Arbeitsschritt'
    )
);
$assert(
    !str_contains($formSource, '$this->feld[6] && $this->task'),
    estab_dv_requirement(
        'NV-07-TKM-WUNSCH',
        'Feld 7 hängt weiter an einer Bedingung, die kein Arbeitsschritt erfüllt'
    )
);

// 6. An empty wish stays valid, an invented medium never reaches the column.
foreach ([
    ['', true, ''],
    ['Fu', true, 'Fu'],
    ['fax', true, 'FAX'],
    ['DFÜ', true, '@'],
    ['Fu,Me', false, ''],
    ['<script>', false, ''],
    [['Fu'], false, ''],
] as [$submitted, $expectedValid, $expectedStored]) {
    $validator = new vali_data_form([
        'task' => 'Stab_schreiben',
        '06_befwegausw' => $submitted,
    ]);
    $validator->checkallfields();
    $assert(
        $validator->validate['06_befwegausw'] === $expectedValid
            && $validator->i_data['06_befwegausw'] === $expectedStored,
        estab_dv_requirement(
            'NV-07-TKM-WUNSCH',
            'Feld 7 prüft den gewünschten Weg falsch: '
                . var_export($submitted, true)
        )
    );
}

// 7. A failed correction keeps the wish the author has just entered.
$authoritative = [
    '00_lfd' => '71',
    '01_medium' => 'Fu',
    '05_gegenstelle' => 'Florian Gegenstelle',
    '06_befweg' => '',
    '06_befwegausw' => '',
    '07_durchspruch' => 'S',
    '09_vorrangstufe' => '',
    '10_anschrift' => 'An Einsatzabschnitt Nord',
    '11_rufnummer' => '',
    '12_anhang' => '',
    '12_betreff' => 'Lageänderung',
    '12_inhalt' => 'Autoritativer Nachrichtentext',
    '12_abfzeit' => '2026-07-31 07:59:00',
    '13_abseinheit' => 'Führungsstelle Nord',
    '14_zeichen' => 's20001',
    '14_funktion' => 'S2',
    '17_vermerke' => 'Rückgabegrund',
];
$correction = estab_rehydrate_authoritative_message_form(
    $authoritative,
    [
        '06_befwegausw' => 'Me',
        '07_durchspruch' => 'D',
        '12_inhalt' => 'Korrigierter Nachrichtentext',
    ],
    'Stab_korrigieren',
    ['14_zeichen' => 's20001', '14_funktion' => 'S2']
);
$assert(
    $correction['06_befwegausw'] === 'Me',
    estab_dv_requirement(
        'NV-07-TKM-WUNSCH',
        'Die Korrektur verliert den soeben eingetragenen Wunsch aus Feld 7'
    )
);

// 8. Both authoring steps actually persist the wish, each in its own branch.
$controllerSource = file_get_contents($root . '/4fach/data_hndl.php');
$assert(
    is_string($controllerSource),
    estab_dv_requirement(
        'NV-07-TKM-WUNSCH',
        'Der Nachrichten-Controller ist nicht lesbar'
    )
);
$controllerSource = (string) $controllerSource;
// The saving switch is the last one in the file; the earlier occurrence only
// decides field access. Slicing per branch keeps the proof from being
// satisfied by two writes inside one and the same workflow step.
$authoringStart = strrpos($controllerSource, 'case "Stab_schreiben":');
$correctionStart = $authoringStart === false
    ? false
    : strpos($controllerSource, 'case "Stab_korrigieren":', $authoringStart);
$correctionEnd = $correctionStart === false
    ? false
    : strpos($controllerSource, 'case "Stab_gesprnoti":', $correctionStart);
$assert(
    is_int($authoringStart)
        && is_int($correctionStart)
        && is_int($correctionEnd)
        && $authoringStart < $correctionStart
        && $correctionStart < $correctionEnd,
    estab_dv_requirement(
        'NV-07-TKM-WUNSCH',
        'Verfassen und Korrigieren sind im Controller nicht auffindbar'
    )
);
$persistence = '"06_befwegausw" => estab_message_medium_storage_value (';
foreach ([
    'Stab_schreiben' => substr(
        $controllerSource,
        (int) $authoringStart,
        (int) $correctionStart - (int) $authoringStart
    ),
    'Stab_korrigieren' => substr(
        $controllerSource,
        (int) $correctionStart,
        (int) $correctionEnd - (int) $correctionStart
    ),
] as $authoringStep => $branchSource) {
    $assert(
        substr_count($branchSource, $persistence) === 1,
        estab_dv_requirement(
            'NV-07-TKM-WUNSCH',
            'Arbeitsschritt ' . $authoringStep
                . ' speichert das gewünschte TK-Mittel nicht'
        )
    );
}
$assert(
    isset(estab_message_columns()['06_befwegausw']),
    estab_dv_requirement(
        'NV-07-TKM-WUNSCH',
        'Feld 7 hat keine Spalte im Nachrichtennachweis'
    )
);

printf(
    "Official message form desired medium: OK (%d assertions)\n",
    $assertions
);
