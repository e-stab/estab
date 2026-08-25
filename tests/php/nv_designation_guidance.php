<?php

declare(strict_types=1);

/**
 * Anschrift und Absender: Dienststelle, nicht Mensch.
 *
 * Die Ausfüllanleitung lässt in beiden Feldern nur Dienststellen-,
 * Teileinheits- oder Einheitsbezeichnungen zu. Der Grund ist nicht Förmlichkeit:
 * Eine Nachricht an „Herrn Meier“ erreicht niemanden, sobald Herr Meier
 * abgelöst ist, und aus dem Nachweis geht später nicht hervor, welche Stelle
 * sie eigentlich bearbeiten sollte.
 *
 * Eine Prüfung, die Eigennamen zuverlässig erkennt, gibt es nicht -- „Wache
 * Meier“ ist eine Dienststelle, „Meier“ nicht, und zwischen beiden liegt kein
 * Muster. Die Anwendung führt deshalb, statt zurückzuweisen: Die Beschriftung
 * am Feld, die Ausfüllhilfe dahinter und der Rückweisungsgrund nennen alle
 * drei dieselbe Bezeichnungsart. Wer trotzdem einen Namen einträgt, tut es
 * nicht aus Unkenntnis.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

/*
 * Die Prüfung braucht beides: die Ansicht und die Eingabeprüfung. Die
 * Laufzeitdateien liegen in 4fach und finden ihre Konfiguration nur von
 * dort, deshalb wird das Verzeichnis für die Ladezeit gewechselt.
 */
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

final class DesignationFixture
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

$fixture = new DesignationFixture();
$guidance = $fixture->official_message_field_guidance();
$help = $fixture->official_message_help_definitions();

/** Nennt dieser Text die geforderte Bezeichnungsart? */
$namesDesignation = static function (string $text): bool {
    $lower = mb_strtolower($text);
    $kinds = 0;
    foreach (['dienststelle', 'teileinheit', 'einheit'] as $kind) {
        $kinds += str_contains($lower, $kind) ? 1 : 0;
    }
    return $kinds >= 3;
};

/** Und sagt er, was nicht hineingehört? */
$excludesPersonalName = static function (string $text): bool {
    return str_contains(mb_strtolower($text), 'eigenname');
};

$fields = [
    '10_anschrift' => ['NV-10-ANSCHRIFT-DIENSTSTELLE', 10, 'Die Anschrift'],
    '13_abseinheit' => ['NV-15-ABSENDER', 15, 'Der Absender'],
];

foreach ($fields as $field => [$rule, $number, $what]) {
    // Der Rückweisungsgrund: gelesen, wenn das Feld beanstandet wird.
    $reason = (string) ($guidance[$field]['reason'] ?? '');
    $assert(
        $namesDesignation($reason),
        estab_dv_requirement(
            $rule,
            $what . ' wird zurückgewiesen mit „' . $reason . '“, ohne die '
                . 'geforderte Bezeichnungsart zu nennen.'
        )
    );
    $assert(
        $excludesPersonalName($reason),
        estab_dv_requirement(
            $rule,
            $what . ' wird zurückgewiesen, ohne zu sagen, dass ein '
                . 'Eigenname nicht zulässig ist.'
        )
    );

    // Die Ausfüllhilfe: gelesen, bevor etwas eingetragen wird.
    $text = (string) ($help[$number]['text'] ?? '');
    $assert(
        $namesDesignation($text),
        estab_dv_requirement(
            $rule,
            'Die Ausfüllhilfe zu Feld ' . $number . ' nennt die geforderte '
                . 'Bezeichnungsart nicht.'
        )
    );
    $assert(
        $excludesPersonalName($text),
        estab_dv_requirement(
            $rule,
            'Die Ausfüllhilfe zu Feld ' . $number . ' sagt nicht, dass ein '
                . 'Eigenname nicht zulässig ist.'
        )
    );
}

/*
 * Und die Beschriftung am Feld: gelesen von jemandem, der weder die Hilfe
 * öffnet noch je eine Rückweisung gesehen hat. Sie ist die einzige der drei
 * Stellen, die ohne eigenes Zutun sichtbar ist.
 */
$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($view)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
$renderStart = (int) strpos($view, 'function plot_official_message_form()');
$render = substr($view, $renderStart);

$cells = [
    'estab-official-address-block' => [
        'NV-10-ANSCHRIFT-DIENSTSTELLE', 'estab-official-address-value',
        'Die Anschrift',
    ],
    'estab-official-sender' => [
        'NV-15-ABSENDER', 'estab-official-sender-value', 'Der Absender',
    ],
];
foreach ($cells as $cell => [$rule, $until, $what]) {
    $start = strpos($render, $cell);
    $end = $start === false ? false : strpos($render, $until, (int) $start);
    $assert(
        $start !== false && $end !== false,
        estab_dv_requirement(
            $rule,
            'Die Zelle ' . $cell . ' steht nicht mehr im Vordruck.'
        )
    );
    if ($start === false || $end === false) {
        continue;
    }
    $heading = substr($render, (int) $start, (int) $end - (int) $start);
    $assert(
        $namesDesignation($heading),
        estab_dv_requirement(
            $rule,
            $what . ' trägt am Feld keinen Hinweis auf die geforderte '
                . 'Bezeichnungsart; wer die Hilfe nicht öffnet, erfährt '
                . 'nichts davon.'
        )
    );
}

/*
 * Zurückgewiesen wird trotzdem nicht: Ein Eintrag, der wie ein Eigenname
 * aussieht, wird angenommen. Eine Prüfung, die "Meier" verwirft, verwirft
 * auch die Ortsfeuerwehr Meiersberg -- und im Einsatz ist eine Maske, die
 * eine richtige Eingabe ablehnt, schlimmer als eine falsche Angabe.
 */
if (!chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    foreach ($fields as $field => [$rule, $number, $what]) {
        foreach (['Meier', 'Ortsverband Heinsberg', 'B 1 Heinsberg'] as $value) {
            $validator = new vali_data_form([$field => $value]);
            $validator->checkallfields();
            $assert(
                ($validator->validate[$field] ?? null) === true,
                estab_dv_requirement(
                    $rule,
                    $what . ' weist den Eintrag „' . $value . '“ zurück. '
                        . 'Eine Maske, die eine richtige Eingabe ablehnt, '
                        . 'ist schlimmer als eine falsche Angabe.'
                )
            );
        }
    }
} finally {
    chdir($previousDirectory);
}

printf("Bezeichnungsart in Anschrift und Absender: OK (%d assertions)\n", $assertions);
