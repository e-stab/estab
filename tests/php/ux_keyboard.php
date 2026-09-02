<?php

declare(strict_types=1);

/**
 * Im Stab wird getippt, nicht gezeigt.
 *
 * Eine Führungsstelle arbeitet mit beiden Händen auf der Tastatur, oft an
 * einem Laptop ohne Maus, oft im Stehen. Ein Bedienelement, das nur mit dem
 * Zeiger erreichbar ist, ist dort nicht erreichbar -- und ein Fokus, den man
 * nicht sieht, ist keiner.
 *
 * Geprüft wird deshalb dreierlei: dass kein Element mit der Tastatur
 * übersprungen wird, dass jedes ausgelöst werden kann, ohne die Maus zu
 * bemühen, und dass die Reihenfolge des Sprungs der Feldfolge des Vordrucks
 * folgt -- ein Vordruck, den man in anderer Reihenfolge durchtabbt als man
 * ihn liest, ist zweimal zu lernen.
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

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($view)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}

/* --- Kein Element wird übersprungen --- */

/*
 * Eine positive Sprungnummer legt eine eigene Reihenfolge fest und stellt
 * damit alles Übrige hinten an. Das ist der klassische Weg, eine
 * Tastaturbedienung zu zerstören, ohne dass es mit der Maus auffiele.
 */
preg_match_all('~tabindex="(-?\d+)"~', $view, $indices);
foreach ($indices[1] as $index) {
    $assert(
        (int) $index <= 0,
        estab_ux_requirement(
            'UX-TASTATUR',
            'Der Vordruck setzt die Sprungnummer ' . $index . '. Eine '
                . 'eigene Reihenfolge stellt alles Übrige hinten an.'
        )
    );
}

/*
 * "tabindex=-1" nimmt ein Element aus dem Sprunglauf. Das ist richtig für
 * Ziele, die angesprungen werden -- eine Fehlerübersicht, ein Anker --, und
 * falsch für alles, was bedient wird. Geprüft wird, dass keine Schaltfläche
 * und kein Eingabefeld so ausgenommen ist.
 */
preg_match_all(
    '~<(input|button|select|textarea|a)\b[^>]*tabindex="-1"[^>]*>~',
    $view,
    $excluded
);
foreach ($excluded[0] as $element) {
    $assert(
        str_contains($element, 'type="hidden"')
            || str_contains($element, 'aria-hidden="true"'),
        estab_ux_requirement(
            'UX-TASTATUR',
            'Ein Bedienelement ist vom Sprunglauf ausgenommen: '
                . substr($element, 0, 90)
        )
    );
}

/* --- Jedes Element ist auslösbar, ohne zu zeigen --- */

/*
 * Ein Klickereignis ohne Tastaturereignis ist der zweite klassische Weg.
 * Die Richtlinie verbietet Ereignisattribute im Markup ohnehin; der Test
 * hält fest, dass auch im mitgelieferten Skript keines auftaucht, das nur
 * auf den Zeiger hört.
 */
foreach (['onclick=', 'onmouseover=', 'onmousedown='] as $handler) {
    $assert(
        !str_contains($view, $handler),
        estab_ux_requirement(
            'UX-TASTATUR',
            'Der Vordruck bindet ' . $handler . ' und ist an dieser Stelle '
                . 'nur mit dem Zeiger bedienbar.'
        )
    );
}

// Die Feldhilfe ist ein Knopf, kein anklickbares Bild: Ein Knopf antwortet
// auf Leertaste und Eingabetaste, ohne dass jemand das nachbauen muss.
$assert(
    preg_match(
        '~<button id="\' \. \$buttonId \. \'" type="button" \'\s*'
            . '\. \'class="estab-official-help-button" \'~',
        $view
    ) === 1
        || (
            str_contains($view, 'class="estab-official-help-button"')
            && str_contains($view, "type=\"button\"")
        ),
    estab_ux_requirement(
        'UX-TASTATUR',
        'Die Feldhilfe ist kein Knopf und antwortet damit nicht von sich '
            . 'aus auf die Tastatur.'
    )
);
$assert(
    str_contains($view, 'aria-expanded'),
    estab_ux_requirement(
        'UX-TASTATUR',
        'Die Feldhilfe sagt nicht, ob sie offen ist; wer sie mit der '
            . 'Tastatur bedient, erfährt es nicht.'
    )
);
$assert(
    str_contains($view, 'event.key !== "Escape"'),
    estab_ux_requirement(
        'UX-TASTATUR',
        'Die Feldhilfe lässt sich nicht mit der Tastatur schliessen.'
    )
);

/*
 * Der waagerecht verschiebbare Rahmen des Vordrucks traegt tabindex="0". Das
 * ist Absicht: Ein Bereich, der gescrollt werden muss, muss auch mit der
 * Tastatur gescrollt werden koennen. Er traegt deshalb eine Beschriftung.
 */
$assert(
    preg_match(
        '~<div class="estab-message-form-scroll" tabindex="0" \'\s*'
            . '\. \'aria-label="~',
        $view
    ) === 1,
    estab_ux_requirement(
        'UX-TASTATUR',
        'Der verschiebbare Rahmen des Vordrucks ist entweder nicht mit der '
            . 'Tastatur erreichbar oder nicht benannt.'
    )
);

/* --- Der Fokus ist sichtbar --- */

$stylesheet = file_get_contents($root . '/estab-ui.css');
if (!is_string($stylesheet)) {
    throw new RuntimeException('Das Stylesheet ist nicht lesbar.');
}
$rules = preg_replace('~/\*.*?\*/~s', ' ', $stylesheet) ?? $stylesheet;
$assert(
    !preg_match('~:focus[^{]*\{[^}]*outline:\s*(none|0)~', $rules),
    estab_ux_requirement(
        'UX-TASTATUR',
        'Irgendwo wird der Fokusrahmen abgeschaltet. Ein Fokus, den man '
            . 'nicht sieht, ist keiner.'
    )
);
$focusRules = preg_match_all('~:focus-visible[^{]*\{~', $rules);
$assert(
    $focusRules >= 10,
    estab_ux_requirement(
        'UX-TASTATUR',
        'Nur ' . $focusRules . ' Regeln zeichnen den Tastaturfokus aus; die '
            . 'Oberfläche liesse den Bedienenden im Unklaren, wo er steht.'
    )
);

/* --- Und die Sprungfolge folgt der Feldfolge des Vordrucks --- */

/*
 * Ohne eigene Sprungnummern folgt der Browser der Reihenfolge des Dokuments.
 * Gemessen wird deshalb, in welcher Reihenfolge die Bedienelemente im
 * Vordruck erscheinen -- und nur sie: Ein schreibgeschütztes Feld hat kein
 * Bedienelement und liegt im Sprunglauf gar nicht.
 *
 * Genau daran war die erste Fassung dieser Prüfung gescheitert. Sie mass die
 * Folge der gedruckten Nummern und fand Feld 5 vor 2 -- richtig gesehen,
 * aber ohne Belang: Die Nummer des Technischen Betriebsbuchs vergibt die
 * Anwendung, sie hat kein Feld zum Hineinspringen.
 */
$render = substr(
    $view,
    (int) strpos($view, 'function plot_official_message_form()')
);

/*
 * Welche gedruckte Nummer trägt ein Feld? Die Ausfüllhilfen führen die
 * Zuordnung; zwei Ankreuzfelder stehen nicht darin und sind hier benannt.
 */
$numbers = [];
foreach ((new class {
    use EstabOfficialMessageFormView;
    public array $formdata = [];
    public array $errorselect = [];
    public array $feld = [];
    public array $activeTelecomRoutes = [];
    public string $task = 'Stab_schreiben';
    public function safe_message_value(string $field): string
    {
        return '';
    }
})->official_message_field_guidance() as $field => $entry) {
    if ((int) $entry['number'] > 0) {
        $numbers[$field] = (int) $entry['number'];
    }
}
$numbers['07_durchspruch'] = 8;
$numbers['11_gesprnotiz'] = 12;

preg_match_all(
    "~official_message_(?:text_input|textarea|radio_group|checkbox)\(\s*\n?"
        . "\s*'([0-9a-z_]+)'"
        . "|official_message_timestamp_block\(\s*\n?\s*'[^']*',\s*(\d+),"
        . "|official_message_priority\(\)"
        . "|official_message_distribution\(\)~",
    $render,
    $controls,
    PREG_SET_ORDER
);

$sequence = [];
foreach ($controls as $control) {
    if (($control[1] ?? '') !== '') {
        $field = $control[1];
        if (!isset($numbers[$field])) {
            continue;
        }
        $sequence[] = [$numbers[$field], $field];
        continue;
    }
    if (($control[2] ?? '') !== '') {
        $sequence[] = [(int) $control[2], 'Stempelblock'];
        continue;
    }
    if (str_contains($control[0], 'official_message_priority')) {
        $sequence[] = [9, 'Vorrangstufe'];
        continue;
    }
    $sequence[] = [19, 'Verteiler'];
}

$assert(
    count($sequence) >= 15,
    estab_ux_requirement(
        'UX-TASTATUR',
        'Es liessen sich nur ' . count($sequence) . ' Bedienelemente '
            . 'auffinden; die Prüfung der Sprungfolge ginge ins Leere.'
    )
);

$previous = null;
foreach ($sequence as [$number, $what]) {
    $assert(
        $previous === null || $number >= $previous[0],
        estab_ux_requirement(
            'UX-TASTATUR',
            'Im Sprunglauf kommt ' . $what . ' (Feld ' . $number
                . ') nach ' . ($previous[1] ?? '') . ' (Feld '
                . ($previous[0] ?? 0) . '). Wer den Vordruck in anderer '
                . 'Reihenfolge durchtabbt als er ihn liest, lernt ihn '
                . 'zweimal.'
        )
    );
    $previous = [$number, $what];
}

printf("Tastaturbedienung: OK (%d assertions)\n", $assertions);
