<?php

declare(strict_types=1);

/**
 * Die Vermerke der Fernmeldezentrale und die Nummer des Betriebsbuchs.
 *
 * Der Nachrichtenvordruck traegt im oberen Teil vier Felder, die nicht dem
 * Stab gehoeren, sondern der Fernmeldezentrale: Aufnahme-, Annahme- und
 * Befoerderungsvermerk sowie die Kennzeichnung im Technischen Betriebsbuch.
 * Sie belegen, wer eine Nachricht wann angenommen und weitergegeben hat --
 * ohne sie ist der Weg einer Nachricht nachtraeglich nicht mehr feststellbar.
 *
 * Die Pflicht wird nicht aus der Erinnerung behauptet, sondern aus der
 * laufenden Pruefung abgeleitet: aus dem Zweig, den checkdata() fuer den
 * jeweiligen Arbeitsschritt auswertet. Wer die Pruefung entfernt, bricht
 * diesen Test, auch wenn der Vordruck das Feld weiterhin anzeigt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

if (!function_exists('estab_message_html')) {
    function estab_message_html(mixed $value): string
    {
        return htmlspecialchars(
            is_scalar($value) ? (string) $value : '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/* ------------------------------------------------------------------ *
 * 1. Pflichtfelder je Arbeitsschritt aus der echten Pruefung ableiten *
 * ------------------------------------------------------------------ */

$validatorSource = file_get_contents($root . '/4fach/vali_data.php');
$assert(
    is_string($validatorSource) && $validatorSource !== '',
    'Die Pruefung des Vordrucks ist nicht lesbar.'
);

$checkStart = strpos($validatorSource, 'function checkdata (){');
$checkEnd = strpos($validatorSource, '}  // checkdata !!!');
$assert(
    $checkStart !== false && $checkEnd !== false && $checkEnd > $checkStart,
    'Der Pruefzweig checkdata() ist nicht mehr auffindbar.'
);
$checkBody = substr($validatorSource, $checkStart, $checkEnd - $checkStart);

/** @var array<string, list<string>> $consulted */
$consulted = [];
if (
    preg_match_all(
        '~case\s+"([^"]+)"\s*:(.*?)(?=case\s+"|\z)~s',
        $checkBody,
        $cases,
        PREG_SET_ORDER
    )
) {
    foreach ($cases as $case) {
        preg_match_all('~validate\["([0-9]{2}_[a-z_]+)"\]~', $case[2], $fields);
        $consulted[$case[1]] = array_values(array_unique($fields[1]));
    }
}
$assert(
    count($consulted) >= 10,
    'checkdata() kennt zu wenige Arbeitsschritte; die Ableitung greift nicht.'
);

$requires = static function (
    string $task,
    string $field
) use ($consulted): bool {
    return in_array($field, $consulted[$task] ?? [], true);
};

/* ------------------------------------------------------ *
 * 2. Feld 2 -- Aufnahmevermerk (Zeit und Handzeichen)    *
 * ------------------------------------------------------ */

foreach (['01_datum' => 'Zeit', '01_zeichen' => 'Handzeichen'] as $field => $part) {
    $assert(
        $requires('FM-Eingang_Anhang', $field),
        estab_dv_requirement(
            'NV-02-AUFNAHMEVERMERK',
            'Die Aufnahme einer eingehenden Nachricht laesst sich ohne '
                . $part . ' abschliessen (' . $field . ').'
        )
    );
}

/* ------------------------------------------------------ *
 * 3. Feld 3 -- Annahmevermerk (Zeit und Handzeichen)     *
 * ------------------------------------------------------ */

foreach (['LdF-Eingang', 'LdF-Ausgang'] as $task) {
    foreach (['02_zeit' => 'Zeit', '02_zeichen' => 'Handzeichen'] as $field => $part) {
        $assert(
            $requires($task, $field),
            estab_dv_requirement(
                'NV-03-ANNAHMEVERMERK',
                'Der Arbeitsschritt ' . $task . ' laesst sich ohne ' . $part
                    . ' des Annahmevermerks abschliessen (' . $field . ').'
            )
        );
    }
}

/* ---------------------------------------------------------- *
 * 4. Feld 4 -- Befoerderungsvermerk (Zeit und Handzeichen)   *
 * ---------------------------------------------------------- */

foreach (['03_datum' => 'Quittungszeit', '03_zeichen' => 'Handzeichen'] as $field => $part) {
    $assert(
        $requires('FM-Ausgang', $field),
        estab_dv_requirement(
            'NV-04-BEFOERDERUNGSVERMERK',
            'Die Befoerderung laesst sich ohne ' . $part
                . ' abschliessen (' . $field . ').'
        )
    );
}

/* ---------------------------------------------------------------- *
 * 5. Feld 5 -- die Nummer stammt aus dem Betriebsbuch des Einsatzes *
 * ---------------------------------------------------------------- */

$originalWorkingDirectory = getcwd();
if (!is_string($originalWorkingDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/official_message_form.php';
} finally {
    chdir($originalWorkingDirectory);
}

$view = new class {
    use EstabOfficialMessageFormView;

    /** @var array<string, mixed> */
    public array $formdata = [];
};

$view->formdata = ['estab_ttb_lfd' => 47, '04_nummer' => 9001];
$assert(
    $view->official_message_ttb_evidence_text() === '47',
    estab_dv_requirement(
        'NV-05-TBB-NUMMER',
        'Feld 5 zeigt nicht den Nachweis aus dem Technischen Betriebsbuch.'
    )
);

$view->formdata = ['04_nummer' => 9001];
$shown = $view->official_message_ttb_evidence_text();
$assert(
    $shown !== '9001',
    estab_dv_requirement(
        'NV-05-TBB-NUMMER',
        'Feld 5 gibt die anwendungsinterne Nummer 04_nummer als Nummer des '
            . 'Technischen Betriebsbuchs aus.'
    )
);
$assert(
    $shown === 'noch kein TBB-Nachweis',
    estab_dv_requirement(
        'NV-05-TBB-NUMMER',
        'Ohne Nachweis im Betriebsbuch benennt Feld 5 diesen Zustand nicht.'
    )
);

$view->formdata = ['estab_ttb_lfd' => '0'];
$assert(
    $view->official_message_ttb_evidence_text() === 'noch kein TBB-Nachweis',
    estab_dv_requirement(
        'NV-05-TBB-NUMMER',
        'Feld 5 gibt eine unzulaessige laufende Nummer als Nachweis aus.'
    )
);

printf("Vermerke der Fernmeldezentrale: OK (%d assertions)\n", $assertions);
