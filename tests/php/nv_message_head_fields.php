<?php

declare(strict_types=1);

/**
 * Vier Felder des Vordrucks, deren Pflicht bisher niemand festhielt.
 *
 * Feld 1 und Feld 7 sehen gleich aus und meinen Verschiedenes: Feld 7 traegt
 * den Wunsch des Verfassers, ueber welchen Weg die Nachricht befoerdert
 * werden soll, Feld 1 den Weg, den die Fernmeldezentrale tatsaechlich benutzt
 * hat. Wer beide zusammenlegt, verliert den Unterschied zwischen Absicht und
 * Nachweis.
 *
 * Feld 11 darf ausdruecklich frei bleiben. Auch das ist eine Anforderung: Ein
 * Feld, das die Ausfuellanleitung freistellt, darf die Anwendung nicht zur
 * Pflicht machen.
 *
 * Die Pflicht wird aus der laufenden Pruefung abgeleitet -- aus dem Zweig, den
 * checkdata() fuer den Arbeitsschritt auswertet, beschraenkt auf die Felder,
 * deren eigene Pruefung einen leeren Eintrag zurueckweist.
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

/* --- Pflichtfelder je Arbeitsschritt aus der echten Pruefung ableiten --- */

$validatorSource = file_get_contents($root . '/4fach/vali_data.php');
$checkStart = strpos((string) $validatorSource, 'function checkdata (){');
$checkEnd = strpos((string) $validatorSource, '}  // checkdata !!!');
$assert(
    $checkStart !== false && $checkEnd !== false && $checkEnd > $checkStart,
    'Der Pruefzweig checkdata() ist nicht mehr auffindbar.'
);
$checkBody = substr((string) $validatorSource, $checkStart, $checkEnd - $checkStart);

preg_match_all('/case "([^"]+)"\s*:/', $checkBody, $cases, PREG_OFFSET_CAPTURE);
$caseCount = count($cases[0]);
$assert($caseCount >= 10, 'checkdata() kennt zu wenige Arbeitsschritte.');

/** @var array<string,list<string>> $consulted */
$consulted = [];
$fallThrough = [];
for ($index = 0; $index < $caseCount; $index++) {
    $task = $cases[1][$index][0];
    $from = $cases[0][$index][1] + strlen($cases[0][$index][0]);
    $to = $index + 1 < $caseCount
        ? $cases[0][$index + 1][1]
        : strlen($checkBody);
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

/** Ein leerer Eintrag scheitert an der Pruefung des Feldes selbst. */
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

/* --- Feld 1: das tatsaechlich benutzte Uebermittlungsmittel --- */

$assert(
    ($consulted['Stab_schreiben'] ?? []) !== []
        || ($consulted['Stab_korrigieren'] ?? []) !== [],
    'Der Arbeitsschritt des Verfassers ist nicht mehr auffindbar.'
);
$assert(
    !in_array('01_medium', $consulted['Stab_korrigieren'] ?? [], true),
    estab_dv_requirement(
        'NV-01-TKM-TATSAECHLICH',
        'Der Verfasser setzt das tatsächlich benutzte Übermittlungsmittel; '
            . 'Feld 1 gehört der Fernmeldezentrale.'
    )
);
foreach (['FM-Eingang_Anhang', 'LdF-Eingang', 'LdF-Ausgang'] as $task) {
    $assert(
        $isRequired($task, '01_medium'),
        estab_dv_requirement(
            'NV-01-TKM-TATSAECHLICH',
            'Der Arbeitsschritt ' . $task . ' lässt den tatsächlich '
                . 'benutzten Weg offen.'
        )
    );
}
$assert(
    !$isRequired('LdF-Ausgang', '06_befwegausw')
        || '01_medium' !== '06_befwegausw',
    estab_dv_requirement(
        'NV-01-TKM-TATSAECHLICH',
        'Feld 1 und Feld 7 teilen sich einen Eintrag.'
    )
);

/* --- Feld 6: Rufname der Gegenstelle --- */

foreach (['FM-Eingang_Anhang', 'LdF-Ausgang'] as $task) {
    $assert(
        $isRequired($task, '05_gegenstelle'),
        estab_dv_requirement(
            'NV-06-RUFNAME',
            'Der Arbeitsschritt ' . $task . ' kommt ohne den Rufnamen der '
                . 'Gegenstelle aus.'
        )
    );
}

/* --- Feld 11: darf frei bleiben --- */

foreach (['Stab_korrigieren', 'Stab_gesprnoti'] as $task) {
    $assert(
        !$isRequired($task, '11_rufnummer'),
        estab_dv_requirement(
            'NV-11-RUFNUMMER',
            'Der Arbeitsschritt ' . $task . ' erzwingt die Rufnummer, obwohl '
                . 'die Ausfüllanleitung das Feld freistellt.'
        )
    );
}

/* --- Feld 13 und Feld 14: Betreff und Text sind zwei Felder --- */

$assert(
    '12_betreff' !== '12_inhalt',
    estab_dv_requirement(
        'NV-13-INHALT-BETREFF',
        'Betreff und Text teilen sich einen Eintrag.'
    )
);
foreach (['Stab_korrigieren', 'Stab_gesprnoti', 'FM-Eingang_Anhang'] as $task) {
    foreach (['12_betreff' => 'Betreff', '12_inhalt' => 'Text'] as $field => $part) {
        $assert(
            $isRequired($task, $field),
            estab_dv_requirement(
                'NV-13-INHALT-BETREFF',
                'Der Arbeitsschritt ' . $task . ' lässt den ' . $part
                    . ' leer (' . $field . ').'
            )
        );
    }
}

printf("Kopffelder des Vordrucks: OK (%d assertions)\n", $assertions);
