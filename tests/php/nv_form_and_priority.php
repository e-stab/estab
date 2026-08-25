<?php

declare(strict_types=1);

/**
 * Die Form der Nachricht und ihre Vorrangstufe.
 *
 * Der Vordruck bietet zwei Formen an: DURCHSAGE und Spruch. Die
 * Ausfüllanleitung nennt den Spruch ausdrücklich die Ausnahme -- er verlangt
 * das buchstabengetreue Verfahren und bindet die Gegenstelle länger. Wer
 * einen Vordruck ohne Vorbelegung vor sich hat, kreuzt jedoch mit gleicher
 * Wahrscheinlichkeit das eine wie das andere an. Die Regel steht damit nur
 * auf dem Papier. Deshalb ist die Durchsage vorbelegt, und der Spruch bleibt
 * eine bewusste Abweichung.
 *
 * Die Vorrangstufe bleibt frei, wenn es keine gibt -- ein leeres Feld ist die
 * Aussage "kein Vorrang", nicht eine vergessene Eingabe.
 *
 * Und der Ausdruck täuscht kein Ankreuzfeld vor, das der amtliche Vordruck
 * nicht hat. Staatsnot ist wählbar, weil eine eingegangene Nachricht sie
 * tragen kann; auf dem Papier gibt es dafür aber kein Kästchen. Sie wird
 * deshalb als Vermerk gedruckt, nicht als angekreuzter Kasten. Ein
 * ausgedruckter Vordruck ist ein Nachweis; ein erfundener Kasten macht ihn
 * zur Fälschung.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

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
require_once $root . '/app/message_priority.php';
require_once $root . '/4fach/official_message_form.php';

final class FormAndPriorityFixture
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

/** Welcher Wert einer Auswahlgruppe ist angekreuzt? */
$checkedValue = static function (string $markup, string $field): ?string {
    preg_match_all(
        '~<input[^>]*id="f_' . preg_quote($field, '~') . '_[^"]*"[^>]*>~',
        $markup,
        $inputs
    );
    foreach ($inputs[0] as $input) {
        if (!str_contains($input, 'checked')) {
            continue;
        }
        if (preg_match('~value="([^"]*)"~', $input, $value) === 1) {
            return $value[1];
        }
    }
    return null;
};

/* --- Die Durchsage ist die Regel, der Spruch die Ausnahme --- */

$empty = new FormAndPriorityFixture();
$empty->feld = [7 => true];
$empty->official_message_preselect_form_type();
$assert(
    ($empty->formdata['07_durchspruch'] ?? null) === 'D',
    estab_dv_requirement(
        'NV-08-DURCHSAGE-SPRUCH',
        'Ein neuer Vordruck belegt die Nachrichtenform mit '
            . var_export($empty->formdata['07_durchspruch'] ?? null, true)
            . ' vor. Ohne Vorbelegung wird der Spruch so oft gewählt wie die '
            . 'Durchsage, und die Ausnahme ist keine mehr.'
    )
);

// Eine getroffene Wahl bleibt stehen, auch die des Spruchs.
$chosen = new FormAndPriorityFixture();
$chosen->feld = [7 => true];
$chosen->formdata = ['07_durchspruch' => 'S'];
$chosen->official_message_preselect_form_type();
$assert(
    ($chosen->formdata['07_durchspruch'] ?? null) === 'S',
    estab_dv_requirement(
        'NV-08-DURCHSAGE-SPRUCH',
        'Die Wahl des Spruchs wird von der Vorbelegung überschrieben.'
    )
);

/*
 * Vorbelegt wird nur, wo abgefasst wird. Ein eingegangener Vordruck ohne
 * Eintrag hatte keinen -- ihn nachträglich zur Durchsage zu erklären wäre
 * eine Angabe, die niemand gemacht hat.
 */
foreach (['FM-Eingang', 'LdF-Eingang', 'Stab_sichten'] as $task) {
    $received = new FormAndPriorityFixture();
    $received->task = $task;
    $received->feld = [7 => false];
    $received->official_message_preselect_form_type();
    $assert(
        ($received->formdata['07_durchspruch'] ?? null) === null,
        estab_dv_requirement(
            'NV-08-DURCHSAGE-SPRUCH',
            'Der Arbeitsschritt ' . $task . ' erklärt eine Nachricht ohne '
                . 'Eintrag nachträglich zur Durchsage.'
        )
    );
}

/*
 * Und die Vorbelegung erreicht den Bildschirm: Sie steht im Vordruck vor der
 * Auswahl, nicht irgendwo. Ohne diesen Nachweis prüfte der Abschnitt oben
 * eine Methode, die niemand aufruft.
 */
$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($view)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
$render_source = substr(
    $view,
    (int) strpos($view, 'function plot_official_message_form()')
);
$preselect = strpos($render_source, 'official_message_preselect_form_type()');
$group = strpos($render_source, "'07_durchspruch',");
$assert(
    $preselect !== false && $group !== false && $preselect < $group,
    estab_dv_requirement(
        'NV-08-DURCHSAGE-SPRUCH',
        'Die Vorbelegung der Nachrichtenform erreicht die Auswahl im '
            . 'Vordruck nicht.'
    )
);
foreach (['DURCHSAGE', 'Spruch'] as $label) {
    $assert(
        str_contains($render_source, "'label' => '" . $label . "'"),
        estab_dv_requirement(
            'NV-08-DURCHSAGE-SPRUCH',
            'Die Nachrichtenform „' . $label . '“ steht nicht mehr im '
                . 'Vordruck.'
        )
    );
}

/* --- Ohne Vorrangstufe bleibt das Feld frei --- */

foreach (['', 'eee'] as $none) {
    $assert(
        estab_message_priority_document_label($none) === '',
        estab_dv_requirement(
            'NV-09-VORRANGSTUFE',
            'Eine Nachricht ohne Vorrangstufe trägt im Dokument den Vermerk '
                . '"' . estab_message_priority_document_label($none) . '".'
        )
    );
}
foreach (['sss' => 'Sofort', 'bbb' => 'Blitz', 'aaa' => 'Staatsnot'] as $value => $label) {
    $assert(
        estab_message_priority_document_label($value) === $label,
        estab_dv_requirement(
            'NV-09-VORRANGSTUFE',
            'Die Vorrangstufe ' . $value . ' erscheint im Dokument als '
                . estab_message_priority_document_label($value) . '.'
        )
    );
}

/* --- Der Ausdruck erfindet kein Ankreuzfeld --- */

/*
 * Der amtliche Vordruck hat zwei Kästchen: Sofort und Blitz. "keine" ist
 * kein Kästchen, sondern die Abwesenheit eines Kreuzes, und Staatsnot steht
 * auf dem Papier gar nicht.
 */
$printedBoxes = ['sss', 'bbb'];

$priorityMarkup = $render(static function () use ($empty): void {
    $empty->feld[9] = true;
    $empty->official_message_priority();
});
preg_match_all(
    '~<label([^>]*)for="f_09_vorrangstufe_[^"]*"~',
    $priorityMarkup,
    $labels,
    PREG_SET_ORDER
);
$assert(
    count($labels) === 4,
    estab_dv_requirement(
        'NV-09-VORRANGSTUFE',
        'Die Vorrangstufe bietet ' . count($labels) . ' statt vier '
            . 'Möglichkeiten an.'
    )
);

foreach (estab_message_priority_options() as $option) {
    if ($option['value'] === '' || in_array($option['value'], $printedBoxes, true)) {
        continue;
    }
    $identifier = 'f_09_vorrangstufe_' . match ($option['value']) {
        'aaa' => 'staatsnot',
        default => $option['value'],
    };
    $position = strpos($priorityMarkup, '<label');
    $offset = strpos($priorityMarkup, $identifier);
    $assert(
        $offset !== false,
        estab_dv_requirement(
            'NV-09-VORRANGSTUFE',
            'Die Vorrangstufe ' . $option['label'] . ' fehlt in der Maske; '
                . 'eine eingegangene Nachricht kann sie tragen.'
        )
    );
    if ($offset === false) {
        continue;
    }
    $labelStart = strrpos(substr($priorityMarkup, 0, $offset), '<label');
    $labelMarkup = substr(
        $priorityMarkup,
        (int) $labelStart,
        $offset - (int) $labelStart
    );
    $assert(
        str_contains($labelMarkup, 'estab-official-priority-extra'),
        estab_dv_requirement(
            'NV-09-VORRANGSTUFE',
            'Die Vorrangstufe ' . $option['label'] . ' wird im Ausdruck als '
                . 'Ankreuzfeld des Vordrucks gesetzt; der amtliche Vordruck '
                . 'hat dafür kein Kästchen.'
        )
    );
}

// Verschwiegen wird sie deswegen nicht: Der Ausdruck trägt sie als Vermerk.
$staatsnot = new FormAndPriorityFixture();
$staatsnot->feld = [9 => true];
$staatsnot->formdata = ['09_vorrangstufe' => 'aaa'];
$staatsnotMarkup = $render(static function () use ($staatsnot): void {
    $staatsnot->official_message_priority();
});
$assert(
    str_contains($staatsnotMarkup, 'estab-official-priority-note')
        && str_contains($staatsnotMarkup, 'Staatsnot'),
    estab_dv_requirement(
        'NV-09-VORRANGSTUFE',
        'Eine Nachricht mit Staatsnot verliert die Angabe im Ausdruck.'
    )
);
$plain = new FormAndPriorityFixture();
$plain->feld = [9 => true];
$plainMarkup = $render(static function () use ($plain): void {
    $plain->official_message_priority();
});
$assert(
    !str_contains($plainMarkup, 'estab-official-priority-note'),
    estab_dv_requirement(
        'NV-09-VORRANGSTUFE',
        'Ein Vordruck ohne Vorrangstufe trägt trotzdem einen Vermerk dazu.'
    )
);

/* --- Und das Stylesheet blendet die erfundenen Kästchen im Druck aus --- */

$stylesheet = file_get_contents($root . '/estab-ui.css');
if (!is_string($stylesheet)) {
    throw new RuntimeException('Das Stylesheet ist nicht lesbar.');
}
$printBlocks = '';
$offset = 0;
while (($start = strpos($stylesheet, '@media print', $offset)) !== false) {
    $depth = 0;
    $index = (int) strpos($stylesheet, '{', $start);
    for ($cursor = $index; $cursor < strlen($stylesheet); $cursor++) {
        $depth += $stylesheet[$cursor] === '{' ? 1 : 0;
        $depth -= $stylesheet[$cursor] === '}' ? 1 : 0;
        if ($depth === 0) {
            $printBlocks .= substr($stylesheet, $start, $cursor - $start + 1);
            $offset = $cursor + 1;
            break;
        }
    }
    if ($depth !== 0) {
        break;
    }
}
foreach (
    ['estab-official-priority-clear' => 'die Möglichkeit „keine“',
        'estab-official-priority-extra' => 'die Stufen ohne Kästchen'] as $class => $what
) {
    $assert(
        str_contains($printBlocks, '.' . $class),
        estab_dv_requirement(
            'NV-09-VORRANGSTUFE',
            'Der Ausdruck behandelt ' . $what . ' nicht gesondert und '
                . 'druckt damit Kästchen, die der Vordruck nicht hat.'
        )
    );
}
$assert(
    str_contains($printBlocks, '.estab-official-priority-note'),
    estab_dv_requirement(
        'NV-09-VORRANGSTUFE',
        'Der Vermerk zur Stufe ohne Kästchen erscheint im Ausdruck nicht.'
    )
);

printf("Nachrichtenform und Vorrangstufe: OK (%d assertions)\n", $assertions);
