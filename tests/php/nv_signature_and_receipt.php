<?php

declare(strict_types=1);

/**
 * Beglaubigung und Quittung: wer eine Nachricht verantwortet und wer sie erhielt.
 *
 * Feld 17 beglaubigt die Nachricht mit Namenszeichen *und* Funktion. Beides
 * zusammen macht sie zurechenbar: Das Zeichen benennt die Person, die Funktion
 * die Rolle, in der sie gehandelt hat. Eine Nachricht mit nur einem der beiden
 * laesst offen, ob sie im Dienst oder daneben entstanden ist.
 *
 * Feld 18 quittiert den Erhalt in der Sichtung. Ohne Quittung ist spaeter
 * nicht feststellbar, ob eine eingegangene Nachricht den Stab je erreicht hat.
 *
 * Die Pflicht wird aus der laufenden Pruefung abgeleitet, nicht behauptet.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

$originalWorkingDirectory = getcwd();
if (!is_string($originalWorkingDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
    require_once $root . '/4fach/vali_data.php';
} finally {
    chdir($originalWorkingDirectory);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$validatorSource = (string) file_get_contents($root . '/4fach/vali_data.php');
$checkStart = strpos($validatorSource, 'function checkdata (){');
$checkEnd = strpos($validatorSource, '}  // checkdata !!!');
$assert(
    $checkStart !== false && $checkEnd !== false && $checkEnd > $checkStart,
    'Der Pruefzweig checkdata() ist nicht mehr auffindbar.'
);
$checkBody = substr($validatorSource, $checkStart, $checkEnd - $checkStart);

preg_match_all('/case "([^"]+)"\s*:/', $checkBody, $cases, PREG_OFFSET_CAPTURE);
$caseCount = count($cases[0]);
$assert($caseCount >= 10, 'checkdata() kennt zu wenige Arbeitsschritte.');

/** @var array<string,list<string>> $consulted */
$consulted = [];
$fallThrough = [];
for ($index = 0; $index < $caseCount; $index++) {
    $task = $cases[1][$index][0];
    $from = $cases[0][$index][1] + strlen($cases[0][$index][0]);
    $to = $index + 1 < $caseCount ? $cases[0][$index + 1][1] : strlen($checkBody);
    preg_match_all(
        '/\$this->validate\s*\[\s*"([^"]+)"\s*\]/',
        substr($checkBody, $from, $to - $from),
        $found
    );
    $fields = array_values(array_unique($found[1]));
    if ($fields === []) {
        $fallThrough[] = $task;
        continue;
    }
    foreach ($fallThrough as $shared) {
        $consulted[$shared] = $fields;
    }
    $fallThrough = [];
    $consulted[$task] = $fields;
}
foreach ($fallThrough as $shared) {
    $consulted[$shared] = [];
}

$rejectsEmptyValue = static function (string $field) use ($root): bool {
    $previous = getcwd();
    if (!is_string($previous) || !chdir($root . '/4fach')) {
        throw new RuntimeException('Cannot enter the message runtime directory');
    }
    try {
        $validator = new vali_data_form([$field => '']);
        $validator->checkallfields();
    } finally {
        chdir($previous);
    }
    return ($validator->validate[$field] ?? false) !== true;
};

$isRequired = static function (string $task, string $field) use (
    $consulted,
    $rejectsEmptyValue
): bool {
    return in_array($field, $consulted[$task] ?? [], true)
        && $rejectsEmptyValue($field);
};

/* --- Feld 17: Namenszeichen und Funktion, beide --- */

foreach (['Stab_korrigieren', 'Stab_gesprnoti'] as $task) {
    foreach (['14_zeichen' => 'Namenszeichen', '14_funktion' => 'Funktion'] as $field => $part) {
        $assert(
            $isRequired($task, $field),
            estab_dv_requirement(
                'NV-17-ZEICHEN-FUNKTION',
                'Der Arbeitsschritt ' . $task . ' beglaubigt die Nachricht '
                    . 'ohne ' . $part . ' (' . $field . ').'
            )
        );
    }
}

/* --- Feld 18: Quittung des Sichters --- */

foreach (['15_quitdatum' => 'Uhrzeit', '15_quitzeichen' => 'Namenszeichen'] as $field => $part) {
    $assert(
        $isRequired('Stab_sichten', $field),
        estab_dv_requirement(
            'NV-18-QUITTUNG',
            'Die Sichtung lässt sich ohne ' . $part . ' der Quittung '
                . 'abschließen (' . $field . ').'
        )
    );
}

/* --- Die Quittungszeit ist vierstellig zu fuehren --- */

$previous = getcwd();
if (!is_string($previous) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    foreach (['12:5', 'zwoelf', '99999'] as $malformed) {
        $validator = new vali_data_form(['15_quitdatum' => $malformed]);
        $validator->checkallfields();
        $assert(
            ($validator->validate['15_quitdatum'] ?? false) !== true,
            estab_dv_requirement(
                'NV-18-QUITTUNG',
                'Die Quittung nimmt die unzulässige Zeitangabe "'
                    . $malformed . '" an.'
            )
        );
    }
} finally {
    chdir($previous);
}

printf("Beglaubigung und Quittung: OK (%d assertions)\n", $assertions);
