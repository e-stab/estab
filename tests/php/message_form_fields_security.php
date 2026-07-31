<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/message_repository.php';
require_once $root . '/4fach/vali_data.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$columns = estab_message_columns();
$assert(
    isset($columns['11_rufnummer'], $columns['12_betreff']),
    'Official phone-number or subject column is not persistable'
);

$normalized = estab_message_fields([
    '11_rufnummer' => '  +49 123 456  ',
    '12_betreff' => '  Lageänderung Süd  ',
]);
$assert(
    $normalized['11_rufnummer'] === '+49 123 456'
        && $normalized['12_betreff'] === 'Lageänderung Süd',
    'Official single-line fields were not normalized consistently'
);
$assert(
    estab_message_fields(['11_rufnummer' => ''])['11_rufnummer'] === '',
    'Optional empty phone number was rejected'
);

$invalidFields = [
    ['11_rufnummer', str_repeat('1', 129)],
    ['11_rufnummer', "123\n456"],
    ['11_rufnummer', "123\u{202E}456"],
    ['11_rufnummer', "\xC3\x28"],
    ['12_betreff', '   '],
    ['12_betreff', str_repeat('ä', 256)],
    ['12_betreff', "Lage\rManipulation"],
    ['12_betreff', "Lage\u{200B}Manipulation"],
    ['12_betreff', ['kein', 'Skalar']],
];
foreach ($invalidFields as [$column, $value]) {
    try {
        estab_message_fields([$column => $value]);
        $assert(false, 'Unsafe official message field was persisted: ' . $column);
    } catch (InvalidArgumentException) {
        $assert(true, 'Unsafe official message field was rejected');
    }
}

$assert(
    estab_message_derived_subject('Lage Süd', 'AW') === 'AW: Lage Süd'
        && estab_message_derived_subject('aw: Lage Süd', 'AW')
            === 'AW: Lage Süd'
        && estab_message_derived_subject('AW: Lage Süd', 'WG')
            === 'WG: AW: Lage Süd'
        && estab_message_derived_subject('', 'WG') === 'WG:',
    'Reply/forward subject derivation is ambiguous'
);
$longDerived = estab_message_derived_subject(str_repeat('ä', 255), 'AW');
$assert(
    str_starts_with($longDerived, 'AW: ')
        && estab_auth_text_length($longDerived) === 255,
    'Derived subject exceeds the database character limit'
);
$assert(
    estab_message_derived_subject("unsicher\ngefälscht", 'AW') === 'AW:',
    'Malformed authoritative legacy subject was reflected'
);
$replyFields = estab_message_followup_contact_fields([
    '11_rufnummer' => ' +49 711 123456 ',
    '12_betreff' => 'Lage Süd',
], 'AW');
$forwardFields = estab_message_followup_contact_fields([
    '11_rufnummer' => ' +49 711 123456 ',
    '12_betreff' => 'Lage Süd',
], 'WG');
$assert(
    $replyFields === [
        '11_rufnummer' => '+49 711 123456',
        '12_betreff' => 'AW: Lage Süd',
    ]
        && $forwardFields === [
            '11_rufnummer' => '',
            '12_betreff' => 'WG: Lage Süd',
    ],
    'Shared reply/forward contact-field semantics changed'
);
$newFollowup = estab_message_followup_new_record([
    '00_lfd' => 73,
    'msglfd' => '73',
    '12_betreff' => 'AW: Lage Süd',
]);
$assert(
    $newFollowup['00_lfd'] === ''
        && !array_key_exists('msglfd', $newFollowup)
        && $newFollowup['12_betreff'] === 'AW: Lage Süd',
    'Derived follow-up retained the source record identity'
);
try {
    estab_message_derived_subject('Lage', 'XX');
    $assert(false, 'Unknown derivation action was accepted');
} catch (InvalidArgumentException) {
    $assert(true, 'Unknown derivation action was rejected');
}

$fieldIsValid = static function (string $field, mixed $value): array {
    $validator = new vali_data_form([$field => $value]);
    $validator->checkallfields();

    return [
        $validator->validate[$field] === true,
        $validator->i_data[$field] ?? null,
    ];
};

[$validPhone, $storedPhone] = $fieldIsValid(
    '11_rufnummer',
    '  +49 711 123456  '
);
$assert(
    $validPhone && $storedPhone === '+49 711 123456',
    'Form validator rejected or failed to normalize a safe phone number'
);
foreach (['', str_repeat('7', 129), "123\n456", "\xC3\x28"] as $index => $phone) {
    [$valid] = $fieldIsValid('11_rufnummer', $phone);
    $assert(
        $valid === ($index === 0),
        'Form validator did not enforce optional phone-number safety'
    );
}
foreach (
    ['Einsatzauftrag', '', '   ', str_repeat('b', 256), "Lage\0Nord"]
    as $index => $subject
) {
    [$valid] = $fieldIsValid('12_betreff', $subject);
    $assert(
        $valid === ($index === 0),
        'Form validator did not enforce the required safe subject'
    );
}

$validatorSource = file_get_contents($root . '/4fach/vali_data.php');
if (!is_string($validatorSource)) {
    throw new RuntimeException('Could not inspect message validator source');
}
$caseSection = static function (
    string $source,
    string $case,
    string $followingCase
): string {
    $start = strpos($source, 'case "' . $case . '"');
    $end = strpos(
        $source,
        'case "' . $followingCase . '"',
        $start === false ? 0 : $start + 1
    );
    if ($start === false || $end === false) {
        throw new RuntimeException('Could not isolate validator case ' . $case);
    }
    return substr($source, $start, $end - $start);
};

foreach ([
    ['FM-Eingang', 'Stab_schreiben'],
    ['Stab_schreiben', 'Stab_gesprnoti'],
    ['Stab_gesprnoti', 'FM-Ausgang'],
] as [$case, $nextCase]) {
    $section = $caseSection($validatorSource, $case, $nextCase);
    $assert(
        str_contains($section, '$this->validate["11_rufnummer"]')
            && str_contains($section, '$this->validate["12_betreff"]'),
        $case . ' does not enforce the optional-field syntax and subject'
    );
}

$controllerSource = file_get_contents($root . '/4fach/mainindex.php');
if (!is_string($controllerSource)) {
    throw new RuntimeException('Could not inspect message controller source');
}
$derivationStart = strpos(
    $controllerSource,
    'Daten kommen vom Formular und sollen als Antwort dienen.'
);
$derivationEnd = strpos(
    $controllerSource,
    'Voreinstellung fuer das Menue',
    $derivationStart === false ? 0 : $derivationStart + 1
);
if ($derivationStart === false || $derivationEnd === false) {
    throw new RuntimeException('Could not isolate follow-up controller section');
}
$derivationSource = substr(
    $controllerSource,
    $derivationStart,
    $derivationEnd - $derivationStart
);
$assert(
    substr_count(
        $derivationSource,
        'estab_message_followup_contact_fields'
    ) === 4,
    'Staff and telecommunications follow-ups do not share one safe derivation'
);
$assert(
    !str_contains($derivationSource, '$formdata = $returnValue'),
    'Reply or forwarding still derives message data from browser fields'
);
$assert(
    str_contains(
        $controllerSource,
        '$_SESSION ["sw_data"] = $objectMessage;'
    ),
    'Telecommunications follow-up session is not database-authoritative'
);
$assert(
    substr_count(
        $derivationSource,
        'estab_message_followup_new_record'
    ) === 4,
    'A reply or forwarding can retain its source record identity'
);

echo 'Official message form fields security: OK (' . $assertions
    . " assertions)\n";
