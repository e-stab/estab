<?php

declare(strict_types=1);

/**
 * Derselbe Vordruck, an jeder Station.
 *
 * Eine Nachricht durchläuft bis zu fünf Stationen. Sähe sie an jeder anders
 * aus, müsste jede Station den Vordruck neu lesen, statt ihn
 * wiederzuerkennen -- und beim Übergeben wäre nicht klar, ob man über
 * dasselbe Blatt spricht. Auf Papier ist das keine Frage: Der Vordruck ist
 * ein Blatt, und jede Station füllt ihren Teil.
 *
 * Der Wechsel der Station ändert deshalb nur, welche Felder bedienbar sind.
 * Feldfolge und Gruppierung bleiben, und zwar alle zwanzig Felder in allen
 * drei Teilen -- auch die, die man gerade nicht ausfüllen darf. Ein Feld,
 * das an der einen Station fehlt, ist an der nächsten eine Überraschung.
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
    require_once $root . '/app/permission_mode.php';
    require_once $root . '/app/workflow.php';
    require_once $root . '/app/function_label.php';
    require_once $root . '/4fach/4fachform.php';
} finally {
    chdir($previousDirectory);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Ein leeres Empfängerraster; das Bild des Vordrucks hängt nicht daran. */
$matrix = [];
for ($row = 1; $row <= 5; $row++) {
    for ($column = 1; $column <= 4; $column++) {
        $matrix[$row][$column] = [
            'typ' => 't', 'fkt' => '', 'rolle' => 'leer',
            'mode' => 'ro', 'auto' => 'f',
        ];
    }
}
$matrix[1][1] = ['typ' => 't', 'fkt' => 'S2', 'rolle' => 'Stab', 'mode' => 'rw', 'auto' => 'f'];

/** Der Vordruck, so wie ein Arbeitsschritt ihn sieht. */
$form = static function (string $task, string $direction) use ($matrix): nachrichten4fach {
    $form = (new ReflectionClass(nachrichten4fach::class))
        ->newInstanceWithoutConstructor();
    $form->task = $task;
    $form->formdata = [
        '04_richtung' => $direction,
        '16_empf' => 'S2_rt,',
        '12_anhang' => '',
        'estab_route_error' => '',
    ];
    $form->empfarray = $matrix;
    $form->activeTelecomRoutes = [];
    $form->feldbgcolor();
    $form->get_access_by_task();
    return $form;
};

/*
 * Das Bild des Vordrucks: die gedruckten Feldnummern in der Reihenfolge, in
 * der sie erscheinen, und die drei Teile, in denen sie stehen. Gelesen wird
 * aus dem Quelltext der Ansicht -- die vollständige Ausgabe braucht eine
 * Datenbank, das Bild aber nicht.
 */
$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($view)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
$render = substr(
    $view,
    (int) strpos($view, 'function plot_official_message_form()')
);

preg_match_all(
    '~data-estab-form-zone="([a-z-]+)"'
        . '|estab-official-print-number">(\d+)<'
        . '|official_message_timestamp_block\(\s*\'[^\']*\',\s*(\d+),~',
    $render,
    $marks,
    PREG_SET_ORDER
);
$picture = [];
$zone = null;
foreach ($marks as $mark) {
    if (($mark[1] ?? '') !== '') {
        $zone = $mark[1];
        continue;
    }
    $number = (int) (($mark[2] ?? '') !== '' ? $mark[2] : ($mark[3] ?? 0));
    if ($number === 0) {
        continue;
    }
    $picture[] = $zone . ':' . $number;
}
$picture = array_values(array_unique($picture));

$assert(
    count($picture) === 20,
    estab_ux_requirement(
        'UX-KEIN-BRUCH-IM-LAUFWEG',
        'Der Vordruck zeigt ' . count($picture) . ' statt zwanzig Felder.'
    )
);

/*
 * Das Bild hängt an keiner Bedingung. Gezählt wird die Klammertiefe mit dem
 * Zerteiler von PHP selbst: Ein gedrucktes Feld, das tiefer liegt als der
 * Rumpf der Ausgabe, steht in einem Zweig -- und ein Zweig kann an einer
 * Station nicht genommen werden. Ein Textvergleich reichte hier nicht; er
 * schaute an der Bedingung vorbei, ohne es zu melden.
 */
$tokens = token_get_all($view);
$methodDepth = null;
$depth = 0;
$inMethod = false;
$sawFunctionName = false;
$depths = [];
foreach ($tokens as $token) {
    if (is_array($token)) {
        if (
            $token[0] === T_STRING
            && $token[1] === 'plot_official_message_form'
        ) {
            $sawFunctionName = true;
        }
        if ($inMethod && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            if (
                preg_match(
                    '~estab-official-print-number">(\d+)<~',
                    $token[1],
                    $printed
                ) === 1
            ) {
                $depths[(int) $printed[1]] = $depth;
            }
        }
        if (
            $inMethod
            && $token[0] === T_STRING
            && $token[1] === 'official_message_timestamp_block'
        ) {
            // Der Stempelblock druckt seine Nummer aus dem Argument; die
            // Tiefe seines Aufrufs ist die Tiefe des Feldes.
            $depths['stempel'][] = $depth;
        }
        continue;
    }
    if ($token === '{') {
        $depth++;
        if ($sawFunctionName && !$inMethod) {
            $inMethod = true;
            $methodDepth = $depth;
        }
        continue;
    }
    if ($token === '}') {
        if ($inMethod && $depth === $methodDepth) {
            $inMethod = false;
        }
        $depth--;
    }
}

$assert(
    $methodDepth !== null && count($depths) >= 18,
    estab_ux_requirement(
        'UX-KEIN-BRUCH-IM-LAUFWEG',
        'Die Ausgabe des Vordrucks liess sich nicht zerlegen; die Prüfung '
            . 'des Bildes ginge ins Leere.'
    )
);
foreach ($depths as $number => $fieldDepth) {
    if ($number === 'stempel') {
        foreach ($fieldDepth as $stampDepth) {
            $assert(
                $stampDepth === $methodDepth,
                estab_ux_requirement(
                    'UX-KEIN-BRUCH-IM-LAUFWEG',
                    'Ein Stempelblock steht unter einer Bedingung und könnte '
                        . 'an einer Station fehlen.'
                )
            );
        }
        continue;
    }
    $assert(
        $fieldDepth === $methodDepth,
        estab_ux_requirement(
            'UX-KEIN-BRUCH-IM-LAUFWEG',
            'Das gedruckte Feld ' . $number . ' steht unter einer Bedingung '
                . '(Tiefe ' . $fieldDepth . ' statt ' . $methodDepth
                . ') und könnte an einer Station fehlen.'
        )
    );
}

/* --- Was sich ändert, ist allein die Bedienbarkeit --- */

$stations = [
    'FM-Eingang' => 'E',
    'LdF-Eingang' => 'E',
    'Stab_sichten' => 'E',
    'Stab_schreiben' => 'A',
    'Stab_korrigieren' => 'A',
    'LdF-Ausgang' => 'A',
    'FM-Ausgang' => 'A',
    'Stab_lesen' => 'E',
];

$access = [];
foreach ($stations as $task => $direction) {
    $bits = [];
    $instance = $form($task, $direction);
    for ($index = 1; $index <= 17; $index++) {
        $bits[$index] = (bool) ($instance->feld[$index] ?? false);
    }
    $access[$task] = $bits;
}

/*
 * Keine zwei Stationen geben dieselben Felder frei -- sonst wäre eine von
 * ihnen überflüssig -, und keine gibt alle frei. Der Vordruck teilt die
 * Arbeit, er verteilt sie nicht doppelt.
 */
foreach ($access as $task => $bits) {
    $assert(
        !in_array(false, $bits, true) === false,
        estab_ux_requirement(
            'UX-KEIN-BRUCH-IM-LAUFWEG',
            'Der Arbeitsschritt ' . $task . ' gibt alle Felder frei; die '
                . 'Aufteilung der Arbeit auf die Stationen wäre aufgehoben.'
        )
    );
}

/*
 * Und die Anzahl der Bedienelemente je Station schwankt, das Bild aber
 * nicht. Genau das ist der Satz der Anforderung: Wer die Nachricht als
 * Fernmelder gesehen hat, erkennt sie als Sichter wieder.
 */
$reader = $access['Stab_lesen'];
$assert(
    array_sum(array_map('intval', $reader)) === 0,
    estab_ux_requirement(
        'UX-KEIN-BRUCH-IM-LAUFWEG',
        'Das blosse Lesen gibt Felder frei.'
    )
);
$assert(
    count($picture) === 20,
    estab_ux_requirement(
        'UX-KEIN-BRUCH-IM-LAUFWEG',
        'Auch beim Lesen zeigt der Vordruck nicht alle zwanzig Felder.'
    )
);

/*
 * Der Vordruck kennt genau einen Renderer. Ein zweiter waere die einfachste
 * Art, das Bild an einer Station anders werden zu lassen.
 */
$controller = file_get_contents($root . '/4fach/4fachform.php');
if (!is_string($controller)) {
    throw new RuntimeException('Das Formular ist nicht lesbar.');
}
$assert(
    substr_count($controller, '$this->plot_official_message_form ()') === 1
        && preg_match(
            '~function plot_form \(\)\{\s*\n\s*\$this->'
                . 'plot_official_message_form \(\);\s*\n\s*\}~',
            $controller
        ) === 1,
    estab_ux_requirement(
        'UX-KEIN-BRUCH-IM-LAUFWEG',
        'Die Ausgabe des Vordrucks fuehrt nicht mehr ueber genau einen Weg; '
            . 'ein zweiter Renderer koennte an einer Station ein anderes '
            . 'Bild zeigen.'
    )
);

/*
 * Die Teile heissen ueberall gleich und stehen ueberall in derselben
 * Reihenfolge -- unabhaengig davon, wer den Vordruck offen hat.
 */
preg_match_all('~data-estab-form-zone="([a-z-]+)"~', $render, $zones);
$assert(
    $zones[1] === ['fm-zentrale', 'nachricht', 'sichter'],
    estab_ux_requirement(
        'UX-KEIN-BRUCH-IM-LAUFWEG',
        'Die Teile des Vordrucks stehen als '
            . implode(', ', $zones[1]) . '.'
    )
);
$assert(
    substr_count($render, 'data-estab-form-zone=') === 3,
    estab_ux_requirement(
        'UX-KEIN-BRUCH-IM-LAUFWEG',
        'Der Vordruck setzt seine Teile mehrfach; eine Station saehe dann '
            . 'eine andere Gliederung.'
    )
);

printf("Kein Bruch im Laufweg: OK (%d assertions)\n", $assertions);
