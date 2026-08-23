<?php

declare(strict_types=1);

/**
 * Führung im Nachrichtenvordruck: Pflichtfeld, Grund, Übersicht, Fokus.
 *
 * Der Vordruck hat eine Rückweisung mit der immer gleichen Marke "Eingabe
 * prüfen" beantwortet: kein Grund, keine Übersicht, kein Sprung zum Feld,
 * und kein Feld war vorher als Pflichtfeld erkennbar. Wer die Nachricht
 * aufnimmt, hat geraten.
 *
 * Die Kennzeichnung darf dabei weder mehr noch weniger versprechen als die
 * Prüfung in 4fach/vali_data.php annimmt. Dieser Test leitet die Pflicht
 * deshalb aus der Quelle jener Prüfung ab: aus dem Zweig, den checkdata()
 * für den Arbeitsschritt auswertet, beschränkt auf die Felder, deren eigene
 * Prüfung einen leeren Eintrag zurückweist. checkdata() selbst ist im Test
 * nicht ausführbar, weil sie fkt_rolle.inc.php und damit die Datenbank
 * einbindet; die Ableitung liest daher die Quelle und prüft jede
 * Einzelbedingung an der laufenden Prüfung nach.
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

final class OfficialMessageGuidanceFixture
{
    use EstabOfficialMessageFormView;

    /** @var array<string,mixed> */
    public array $formdata = [];

    /** @var array<string,bool> */
    public array $errorselect = [];

    /** @var array<int,bool> */
    public array $feld = [];

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

/** Render one trait method and return its markup. */
$render = static function (callable $callback): string {
    ob_start();
    $callback();
    return (string) ob_get_clean();
};

/* ------------------------------------------------------------------ *
 * 1. Pflichtfelder aus der Prüfung ableiten, nicht aus der Erinnerung *
 * ------------------------------------------------------------------ */

$validatorSource = file_get_contents($root . '/4fach/vali_data.php');
$assert(
    is_string($validatorSource) && $validatorSource !== '',
    'Die Prüfung des Vordrucks ist nicht lesbar.'
);
$checkStart = strpos($validatorSource, 'function checkdata (){');
$checkEnd = strpos($validatorSource, '}  // checkdata !!!');
$assert(
    is_int($checkStart) && is_int($checkEnd) && $checkEnd > $checkStart,
    'Der Prüfzweig checkdata() ist nicht mehr auffindbar.'
);
$checkBody = substr($validatorSource, $checkStart, $checkEnd - $checkStart);

/** @var array<string,string> $taskSource */
$taskSource = [];
preg_match_all('/case "([^"]+)"\s*:/', $checkBody, $cases, PREG_OFFSET_CAPTURE);
$caseCount = count($cases[0]);
$assert($caseCount >= 10, 'checkdata() kennt zu wenige Arbeitsschritte.');
for ($index = 0; $index < $caseCount; $index++) {
    $from = $cases[0][$index][1] + strlen($cases[0][$index][0]);
    $to = $index + 1 < $caseCount
        ? $cases[0][$index + 1][1]
        : strlen($checkBody);
    $taskSource[$cases[1][$index][0]] = substr($checkBody, $from, $to - $from);
}

/** @var array<string,list<string>> $consultedFields */
$consultedFields = [];
$fallThrough = [];
foreach ($taskSource as $task => $chunk) {
    preg_match_all(
        '/\$this->validate\s*\[\s*"([^"]+)"\s*\]/',
        $chunk,
        $found
    );
    $fields = array_values(array_unique($found[1]));
    if ($fields === []) {
        // "case A: case B:" teilt sich einen Zweig.
        $fallThrough[] = $task;
        continue;
    }
    foreach ($fallThrough as $sharedTask) {
        $consultedFields[$sharedTask] = $fields;
    }
    $fallThrough = [];
    $consultedFields[$task] = $fields;
}
foreach ($fallThrough as $sharedTask) {
    $consultedFields[$sharedTask] = [];
}

/** Ein leerer Eintrag scheitert an der Prüfung des Feldes selbst. */
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

// Feld 6 lässt die Feldprüfung leer zu; der Zweig LdF-Ausgang verlangt den
// Beförderungsweg zusätzlich ausdrücklich.
preg_match_all(
    '/trim \(\(string\) \(\$this->i_data \["([^"]+)"\]/',
    $checkBody,
    $explicitlyDemanded
);
$demandedInBranch = array_values(array_unique($explicitlyDemanded[1]));
$assert(
    $demandedInBranch === ['06_befweg'],
    'Der Prüfzweig verlangt unerwartete Felder ausdrücklich: '
        . implode(', ', $demandedInBranch)
);

/** @var array<string,list<string>> $derivedRequirement */
$derivedRequirement = [];
foreach ($consultedFields as $task => $fields) {
    $required = [];
    foreach ($fields as $field) {
        if (
            $rejectsEmptyValue($field)
            || in_array($field, $demandedInBranch, true)
        ) {
            $required[] = $field;
        }
    }
    sort($required, SORT_STRING);
    $derivedRequirement[$task] = $required;
}

$assert(
    $derivedRequirement['Stab_schreiben'] !== []
        && !in_array('11_rufnummer', $derivedRequirement['Stab_schreiben'], true)
        && !in_array(
            '09_vorrangstufe',
            $derivedRequirement['Stab_schreiben'],
            true
        ),
    estab_dv_requirement(
        'NV-PFLICHTFELDER',
        'Die Ableitung hält Feld 9 oder Feld 11 für zwingend, obwohl die '
            . 'Prüfung beide leer annimmt.'
    )
);

/**
 * Die Anwendung kennt zu einem Arbeitsschritt mehrere Lagen: mit und ohne
 * veröffentlichten S6-Plan, Eingang und Ausgang. Verglichen wird die
 * Vereinigung über alle Lagen.
 *
 * @var array<string,list<array{routes:list<array<string,mixed>>,direction:string}>>
 */
/*
 * Die Betriebsart entscheidet mit: ohne Plan disponiert der LdF nur im Modus
 * LOCKER unmittelbar. Im Modus STRENG nimmt die Prüfung das nicht an, deshalb
 * darf die Maske dort auch nichts verlangen. Beide Fälle werden gefahren.
 */
$contexts = [
    'LdF-Ausgang' => [
        ['routes' => [], 'direction' => 'A', 'mode' => 'LOOSE'],
        ['routes' => [], 'direction' => 'A', 'mode' => 'STRICT'],
        [
            'routes' => [['fernmeldeplan_eintrag_id' => 7]],
            'direction' => 'A',
        ],
    ],
    'Stab_sichten' => [
        ['routes' => [], 'direction' => 'E'],
        ['routes' => [], 'direction' => 'A'],
    ],
];

$fixture = new OfficialMessageGuidanceFixture();
foreach ($derivedRequirement as $task => $expected) {
    $announced = [];
    foreach ($contexts[$task] ?? [['routes' => [], 'direction' => 'E']] as $context) {
        $fixture->task = $task;
        $fixture->activeTelecomRoutes = $context['routes'];
        $fixture->formdata = ['04_richtung' => $context['direction']];
        if (isset($context['mode'])) {
            $GLOBALS[ESTAB_PERMISSION_CONTEXT_KEY] = [
                'mode' => $context['mode'] === 'LOOSE'
                    ? ESTAB_PERMISSION_MODE_LOOSE
                    : ESTAB_PERMISSION_MODE_STRICT,
            ];
        } else {
            unset($GLOBALS[ESTAB_PERMISSION_CONTEXT_KEY]);
        }
        foreach ($fixture->official_message_required_fields() as $field) {
            if (!in_array($field, $announced, true)) {
                $announced[] = $field;
            }
        }
    }
    sort($announced, SORT_STRING);
    $assert(
        $announced === $expected,
        estab_dv_requirement(
            'NV-PFLICHTFELDER',
            'Der Vordruck kennzeichnet für ' . $task . ' [ '
                . implode(', ', $announced) . ' ], die Prüfung verlangt [ '
                . implode(', ', $expected) . ' ].'
        )
    );
}

$fixture->task = 'Stab_lesen';
$fixture->formdata = [];
$fixture->activeTelecomRoutes = [];
$assert(
    $fixture->official_message_required_fields() === [],
    'Die reine Leseansicht verlangt Eingaben.'
);

/* ------------------------------------------------- *
 * 2. Jede Kennzeichnung trägt einen benennbaren Grund *
 * ------------------------------------------------- */

$guidance = $fixture->official_message_field_guidance();
$helpNumbers = array_keys($fixture->official_message_help_definitions());
foreach ($guidance as $field => $entry) {
    $assert(
        is_int($entry['number'])
            && ($entry['number'] === 0
                || in_array($entry['number'], $helpNumbers, true)),
        estab_dv_requirement(
            'NV-FELDNUMMERN',
            'Feld ' . $field . ' nennt die Nummer ' . $entry['number']
                . ', die der Vordruck nicht kennt.'
        )
    );
    $assert(
        $entry['label'] !== ''
            && $entry['hint'] !== ''
            && mb_strlen($entry['hint'], 'UTF-8') <= 20
            && str_ends_with($entry['reason'], '.')
            && mb_strlen($entry['reason'], 'UTF-8') > 20,
        estab_dv_requirement(
            'NV-PFLICHTFELDER',
            'Feld ' . $field . ' hat keine kurze Marke oder keinen Grund.'
        )
    );
}
$hints = array_column($guidance, 'hint');
$assert(
    count(array_unique($hints)) >= 10,
    estab_dv_requirement(
        'NV-PFLICHTFELDER',
        'Die Felder tragen wieder überwiegend dieselbe Marke.'
    )
);
foreach (['01_datum', '02_zeit', '03_datum', '12_abfzeit', '15_quitdatum'] as $timeField) {
    $assert(
        str_contains($guidance[$timeField]['reason'], 'vierstellig'),
        estab_dv_requirement(
            'NV-ZEIT-FORMAT',
            'Der Grund zu ' . $timeField . ' nennt das Zeitformat nicht.'
        )
    );
}
foreach ($derivedRequirement as $task => $expected) {
    foreach ($expected as $field) {
        $assert(
            isset($guidance[$field]),
            estab_dv_requirement(
                'NV-PFLICHTFELDER',
                'Das Pflichtfeld ' . $field . ' aus ' . $task
                    . ' hat keinen Eintrag in der Feldkunde.'
            )
        );
    }
}

/* -------------------------------------- *
 * 3. Kennzeichnung und Marke am Feld      *
 * -------------------------------------- */

$fixture->task = 'Stab_schreiben';
$fixture->formdata = [];
$fixture->errorselect = [];
$fixture->officialMessageMarkedFields = [];
$plainSubject = $render(static function () use ($fixture): void {
    $fixture->official_message_text_input('12_betreff', true, 255, 'Betreff');
});
$assert(
    str_contains($plainSubject, 'aria-required="true"')
        && str_contains($plainSubject, 'data-estab-required="true"')
        && !str_contains($plainSubject, 'aria-invalid')
        && !str_contains($plainSubject, 'estab-official-field-error'),
    estab_dv_requirement(
        'NV-PFLICHTFELDER',
        'Ein Pflichtfeld ohne Beanstandung ist nicht als Pflicht erkennbar.'
    )
);

$optional = $render(static function () use ($fixture): void {
    $fixture->official_message_text_input('11_rufnummer', true, 128, 'Ruf Nr.');
});
$assert(
    !str_contains($optional, 'aria-required')
        && !str_contains($optional, 'data-estab-required'),
    estab_dv_requirement(
        'NV-PFLICHTFELDER',
        'Feld 11 wird als Pflicht ausgewiesen, obwohl die Prüfung es leer '
            . 'annimmt.'
    )
);

$locked = $render(static function () use ($fixture): void {
    $fixture->official_message_text_input('12_betreff', false, 255, 'Betreff');
});
$assert(
    !str_contains($locked, 'data-estab-required')
        && str_contains($locked, 'estab-official-readonly'),
    'Ein gesperrtes Feld trägt eine Pflichtkennzeichnung, die niemand '
        . 'erfüllen kann.'
);

$fixture->errorselect = ['12_betreff' => false, '10_anschrift' => false];
$fixture->officialMessageMarkedFields = [];
$markedSubject = $render(static function () use ($fixture): void {
    $fixture->official_message_text_input('12_betreff', true, 255, 'Betreff');
});
$assert(
    str_contains($markedSubject, 'aria-invalid="true"')
        && str_contains(
            $markedSubject,
            'aria-describedby="estab-field-error-12_betreff"'
        )
        && str_contains($markedSubject, 'id="estab-field-error-12_betreff"')
        && str_contains($markedSubject, $guidance['12_betreff']['hint'])
        && str_contains($markedSubject, $guidance['12_betreff']['reason'])
        && !str_contains($markedSubject, 'Eingabe prüfen'),
    estab_dv_requirement(
        'NV-PFLICHTFELDER',
        'Die Marke am Feld nennt den Grund der Rückweisung nicht.'
    )
);

$suggestionField = $render(static function () use ($fixture): void {
    $fixture->errorselect = ['05_gegenstelle' => false];
    $fixture->official_message_text_input(
        '05_gegenstelle',
        true,
        128,
        'Rufname',
        ' aria-describedby="estab-suggestion-hint"'
    );
});
$assert(
    substr_count($suggestionField, 'aria-describedby=') === 1
        && str_contains(
            $suggestionField,
            'aria-describedby="estab-field-error-05_gegenstelle '
                . 'estab-suggestion-hint"'
        ),
    'Die Fehlermarke verdrängt die Beschreibung der Vorschlagsliste.'
);

$fixture->errorselect = ['10_anschrift' => false];
$markedAddress = $render(static function () use ($fixture): void {
    $fixture->official_message_textarea('10_anschrift', true, 'Anschrift', 255);
});
$assert(
    str_contains($markedAddress, 'data-estab-required="true"')
        && str_contains(
            $markedAddress,
            'aria-describedby="estab-field-error-10_anschrift"'
        )
        && str_contains($markedAddress, $guidance['10_anschrift']['hint']),
    'Der Textbereich trägt weder Pflichtkennzeichen noch benannten Grund.'
);

/* ------------------------------------------ *
 * 4. Fehlerübersicht mit Sprungmarken am Kopf *
 * ------------------------------------------ */

$fixture->task = 'FM-Eingang';
$fixture->formdata = ['04_richtung' => 'E'];
$fixture->errorselect = [
    '01_medium' => false,
    '12_betreff' => false,
    '12_inhalt' => true,
];
$fixture->officialMessageMarkedFields = [];
$render(static function () use ($fixture): void {
    $fixture->official_message_text_input('12_betreff', true, 255, 'Betreff');
});
$summary = $render(static function () use ($fixture): void {
    $fixture->official_message_error_summary();
});
$assert(
    str_contains($summary, 'data-estab-form-error-summary="2"')
        && str_contains($summary, 'role="alert"')
        && str_contains($summary, 'href="#f_01_medium_fu"')
        && str_contains($summary, 'href="#f_12_betreff"')
        && str_contains($summary, 'Feld 1 ')
        && str_contains($summary, 'Feld 13 ')
        && !str_contains($summary, 'f_12_inhalt'),
    estab_dv_requirement(
        'NV-PFLICHTFELDER',
        'Die Fehlerübersicht benennt nicht jedes beanstandete Feld mit '
            . 'Sprungmarke.'
    )
);
$assert(
    strpos($summary, 'f_01_medium_fu') < strpos($summary, 'f_12_betreff')
        && str_contains(
            $summary,
            'data-estab-form-error-focus="f_01_medium_fu"'
        ),
    'Die Übersicht folgt nicht der Reihenfolge des Vordrucks.'
);

$fixture->task = 'Stab_sichten';
$fixture->formdata = ['04_richtung' => 'A'];
$fixture->errorselect = ['17_vermerke' => false];
$fixture->officialMessageMarkedFields = [];
$render(static function () use ($fixture): void {
    $fixture->official_message_textarea('17_vermerke', true, 'Vermerke');
});
$reviewSummary = $render(static function () use ($fixture): void {
    $fixture->official_message_error_summary();
});
$assert(
    str_contains($reviewSummary, 'href="#f_17_vermerke"')
        && str_contains($reviewSummary, 'Feld 20 ')
        && !str_contains($reviewSummary, 'f_15_quitdatum'),
    'Eine Rückweisung außerhalb der Pflichtfelder fehlt in der Übersicht, '
        . 'oder die Übersicht erfindet Beanstandungen.'
);

$fixture->task = 'Stab_schreiben';
$fixture->formdata = [];
$fixture->errorselect = [];
$fixture->officialMessageMarkedFields = [];
$assert(
    $render(static function () use ($fixture): void {
        $fixture->official_message_error_summary();
    }) === '',
    'Der unbeschriebene Vordruck meldet bereits Fehler.'
);

/* ----------------------------------------- *
 * 5. Quelltext- und Gestaltungszusicherungen *
 * ----------------------------------------- */

$view = file_get_contents($root . '/4fach/official_message_form.php');
$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(
    is_string($view) && is_string($stylesheet),
    'Ansicht oder Stylesheet des Vordrucks sind nicht lesbar.'
);
$assert(
    !str_contains($view, "'Eingabe prüfen</span>'"),
    estab_dv_requirement(
        'NV-PFLICHTFELDER',
        'Die alte Sammelmarke steht wieder im Vordruck.'
    )
);
$assert(
    str_contains($view, 'data-estab-official-form-focus')
        && str_contains($view, 'data-estab-form-error-focus')
        && substr_count($view, 'ob_start();') === 1
        && substr_count($view, 'ob_get_clean()') === 1,
    'Der Autofokus oder der Zwischenspeicher der Übersicht fehlt.'
);
$assert(
    str_contains(
        $stylesheet,
        '.estab-official-input[data-estab-required="true"],'
    )
        && preg_match(
            '~\.estab-official-readonly \{[^}]*background:~',
            $stylesheet
        ) === 1
        && preg_match(
            '~\.estab-official-box-choice:disabled \{[^}]*background:~',
            $stylesheet
        ) === 1,
    'Pflichtfeld und gesperrtes Feld sind im Stylesheet nicht unterscheidbar.'
);
$assert(
    preg_match(
        '~\.estab-official-address-value,\s*\.estab-official-phone-value,'
            . '\s*\.estab-official-composition-value \{\s*'
            . 'position: relative;~',
        $stylesheet
    ) === 1,
    'Die Wertfelder verankern ihre Fehlermarke nicht selbst, so dass die '
        . 'Marke der Felder 10 und 11 wieder am Kopf der Zone erscheint.'
);
$printBlockStart = strpos($stylesheet, '@media print');
$printBlock = is_int($printBlockStart)
    ? substr($stylesheet, $printBlockStart, 6000)
    : '';
$assert(
    str_contains($printBlock, '.estab-official-field-error,')
        && str_contains($printBlock, '.estab-message-error-summary,')
        && str_contains($printBlock, 'box-shadow: none;'),
    'Kennzeichnungen und Fehlermarken erscheinen auf dem Ausdruck.'
);
$heightBlocks = substr_count($stylesheet, '@media (max-height:');
$assert(
    $heightBlocks >= 2
        && substr_count($stylesheet, '.estab-message-error-summary {') >= 3,
    'Die Fehlerübersicht folgt den Dichtestufen flacher Bildschirme nicht.'
);

/*
 * Ohne veröffentlichten S6-Plan darf das Formular die manuelle Disposition
 * nur dort anbieten, wo die Prüfung sie auch annimmt: vali_data lässt sie
 * ausschliesslich zu, solange kein Plan verlangt wird. Im Modus STRENG ohne
 * gültigen Plan bot die Maske sonst Felder an, forderte deren Ausfüllung und
 * wies das Speichern anschliessend zwingend ab.
 */
$assert(
    str_contains(
        $view,
        'function official_message_manual_disposition(): bool'
    )
    && preg_match(
        '~official_message_manual_disposition\(\)[\s\S]{0,400}?'
        . 'estab_permission_telecom_plan_required~',
        $view
    ) === 1,
    'Die Maske entscheidet ohne Plan nicht nach dem Berechtigungsmodus.'
);
$assert(
    substr_count($view, '$this->official_message_manual_disposition()') >= 3,
    'Nicht alle drei Stellen der manuellen Disposition fragen den Modus ab.'
);
$assert(
    str_contains($view, 'estab-message-plan-blocked'),
    'Im Modus STRENG ohne Plan fehlt der Sperrhinweis.'
);

echo 'Official message form guidance: OK (' . $assertions
    . " assertions)\n";
