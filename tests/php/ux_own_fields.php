<?php

declare(strict_types=1);

/**
 * Welche Felder gehören mir -- und wie erkenne ich das ohne Farbe?
 *
 * Der Vordruck ist einer, und jede Station füllt ihren Teil. Fremde Felder
 * bleiben deshalb sichtbar; bedienbar sind sie nicht. So weit ist das
 * unstrittig.
 *
 * Strittig wird es beim Erkennen. Wer die zuständigen Felder allein an
 * einer Farbe erkennt, erkennt sie nicht: bei Farbfehlsichtigkeit nicht, im
 * Sonnenlicht auf einem Laptopbildschirm nicht, auf einem
 * Schwarzweißausdruck nicht. Eine Führungsstelle arbeitet in allen drei
 * Lagen.
 *
 * Deshalb trägt jedes Feld seinen Zustand als Merkmal -- Pflicht, zuständig
 * oder fremd --, jedes Pflichtfeld eine sichtbare Marke, und die Erklärung
 * am Seitenkopf nennt die Marke statt nur die Farbe.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';

$previousDirectory = getcwd();
if (!is_string($previousDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
    require_once $root . '/4fach/vali_data.php';
} finally {
    chdir($previousDirectory);
}
require_once $root . '/app/permission_mode.php';
require_once $root . '/4fach/official_message_form.php';

final class OwnFieldsFixture
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
$render = static function (callable $callback): string {
    ob_start();
    try {
        $callback();
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
};

/* --- Jedes Feld nennt seinen Zustand --- */

$states = ['pflicht', 'zustaendig', 'fremd'];

foreach (
    [
        'Stab_schreiben' => ['12_betreff', true, 'pflicht'],
        'Stab_sichten' => ['15_quitzeichen', true, 'pflicht'],
    ] as $task => [$field, $editable, $expected]
) {
    $fixture = new OwnFieldsFixture();
    $fixture->task = $task;
    $fixture->formdata = ['04_richtung' => 'E'];
    $markup = $render(static function () use ($fixture, $field, $editable): void {
        $fixture->official_message_text_input($field, $editable, 255, 'Feld');
    });
    $assert(
        str_contains($markup, 'data-estab-field-state="' . $expected . '"'),
        estab_ux_requirement(
            'UX-MEINE-FELDER-OHNE-FARBE',
            'Das Feld ' . $field . ' bei ' . $task . ' nennt seinen Zustand '
                . 'nicht als „' . $expected . '“.'
        )
    );
    $assert(
        str_contains($markup, 'estab-official-required-mark'),
        estab_ux_requirement(
            'UX-MEINE-FELDER-OHNE-FARBE',
            'Das Pflichtfeld ' . $field . ' bei ' . $task . ' trägt keine '
                . 'sichtbare Marke; ohne Farbe bliebe es ununterscheidbar.'
        )
    );
}

// Ein zuständiges Feld ohne Pflicht trägt keine Marke, nennt aber seinen
// Zustand: Eine Marke an jedem Feld benennt nichts mehr.
$optional = new OwnFieldsFixture();
$optional->task = 'Stab_schreiben';
$optionalMarkup = $render(static function () use ($optional): void {
    $optional->official_message_text_input('11_rufnummer', true, 128, 'Ruf Nr.');
});
$assert(
    str_contains($optionalMarkup, 'data-estab-field-state="zustaendig"')
        && !str_contains($optionalMarkup, 'estab-official-required-mark'),
    estab_ux_requirement(
        'UX-MEINE-FELDER-OHNE-FARBE',
        'Ein zuständiges Feld ohne Pflicht ist nicht von einem Pflichtfeld '
            . 'zu unterscheiden.'
    )
);

// Und ein fremdes Feld nennt sich fremd -- und sagt es der Vorlesehilfe.
$foreign = new OwnFieldsFixture();
$foreign->task = 'Stab_sichten';
$foreignMarkup = $render(static function () use ($foreign): void {
    $foreign->official_message_text_input('12_betreff', false, 255, 'Betreff');
});
$assert(
    str_contains($foreignMarkup, 'data-estab-field-state="fremd"'),
    estab_ux_requirement(
        'UX-MEINE-FELDER',
        'Ein Feld, das dieser Station nicht gehört, nennt sich nicht als '
            . 'solches.'
    )
);
$assert(
    str_contains($foreignMarkup, 'schreibgeschützt')
        && str_contains($foreignMarkup, 'data-estab-readonly="true"'),
    estab_ux_requirement(
        'UX-MEINE-FELDER',
        'Ein fremdes Feld ist nicht als schreibgeschützt benannt.'
    )
);
$assert(
    !str_contains($foreignMarkup, '<input type="text"')
        && !str_contains($foreignMarkup, '<textarea'),
    estab_ux_requirement(
        'UX-MEINE-FELDER',
        'Ein fremdes Feld ist trotzdem bedienbar.'
    )
);

/* --- Die drei Zustände unterscheiden sich ohne Farbe --- */

$stylesheet = file_get_contents($root . '/estab-ui.css');
if (!is_string($stylesheet)) {
    throw new RuntimeException('Das Stylesheet ist nicht lesbar.');
}

/** Alle Regeln eines Auswählers, zusammengefasst. */
$declarationsFor = static function (
    string $stylesheet,
    string $needle
): array {
    $declarations = [];
    preg_match_all('~([^{}]+)\{([^{}]*)\}~', $stylesheet, $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        if (!str_contains($rule[1], $needle)) {
            continue;
        }
        foreach (explode(';', $rule[2]) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $declarations[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return $declarations;
};

/*
 * Farbe zählt nicht. Was zählt, ist Form: Rahmenstärke, Rahmenart, ein
 * Zeichen, das gesetzt wird, eine andere Schriftstärke. All das übersteht
 * einen Schwarzweißausdruck.
 */
$colourOnly = ['color', 'background-color', 'background', 'box-shadow'];
foreach ($states as $state) {
    $declarations = $declarationsFor(
        $stylesheet,
        'data-estab-field-state="' . $state . '"'
    );
    $assert(
        $declarations !== [],
        estab_ux_requirement(
            'UX-MEINE-FELDER-OHNE-FARBE',
            'Der Zustand „' . $state . '“ wird nicht dargestellt.'
        )
    );
    $shape = array_diff(array_keys($declarations), $colourOnly);
    $assert(
        $shape !== [],
        estab_ux_requirement(
            'UX-MEINE-FELDER-OHNE-FARBE',
            'Der Zustand „' . $state . '“ unterscheidet sich allein durch '
                . 'Farbe (' . implode(', ', array_keys($declarations))
                . '). Auf einem Schwarzweißausdruck wäre er nicht mehr '
                . 'erkennbar.'
        )
    );
}

// Und die drei unterscheiden sich auch voneinander, nicht nur vom Nichts.
$shapes = [];
foreach ($states as $state) {
    $declarations = $declarationsFor(
        $stylesheet,
        'data-estab-field-state="' . $state . '"'
    );
    $shapes[$state] = array_intersect_key(
        $declarations,
        array_flip(array_diff(array_keys($declarations), $colourOnly))
    );
}
foreach ([['pflicht', 'zustaendig'], ['zustaendig', 'fremd'], ['pflicht', 'fremd']] as [$left, $right]) {
    $assert(
        $shapes[$left] !== $shapes[$right],
        estab_ux_requirement(
            'UX-MEINE-FELDER-OHNE-FARBE',
            'Die Zustände „' . $left . '“ und „' . $right . '“ sehen ohne '
                . 'Farbe gleich aus.'
        )
    );
}

// Die Marke selbst ist ein Zeichen, kein Farbfleck.
$mark = $declarationsFor($stylesheet, '.estab-official-required-mark');
$assert(
    $mark !== [] && array_diff(array_keys($mark), $colourOnly) !== [],
    estab_ux_requirement(
        'UX-MEINE-FELDER-OHNE-FARBE',
        'Die Pflichtmarke ist nur ein Farbfleck.'
    )
);

/* --- Und die Erklärung am Seitenkopf nennt nicht nur die Farbe --- */

$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($view)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
$header = substr(
    $view,
    (int) strpos($view, 'estab-message-page-header'),
    2000
);
$assert(
    str_contains($header, 'Arbeitsschritt'),
    estab_ux_requirement(
        'UX-MEINE-FELDER',
        'Der Seitenkopf erklärt nicht, welche Felder zum Arbeitsschritt '
            . 'gehören.'
    )
);
$assert(
    str_contains($header, 'Stern') || str_contains($header, 'Marke'),
    estab_ux_requirement(
        'UX-MEINE-FELDER-OHNE-FARBE',
        'Der Seitenkopf erklärt die zuständigen Felder allein über ihre '
            . 'Farbe. Wer die Farbe nicht sieht, findet sie nicht.'
    )
);

printf("Zuständigkeit ohne Farbe: OK (%d assertions)\n", $assertions);
