<?php

declare(strict_types=1);

/**
 * Das Papierbild: Wer den Vordruck kennt, soll die Maske wiedererkennen.
 *
 * Die Anwendung wird von Menschen bedient, die das Papier kennen und die
 * Software nicht. Weicht die Maske vom Vordruck ab -- andere Reihenfolge,
 * andere Begriffe, Felder auf mehreren Seiten --, muss jeder Anwender zweimal
 * lernen: einmal die Sache und einmal die Anwendung. Im Einsatz führt das
 * zurück zum Papier, und dann nützt die beste Nachweisführung nichts.
 *
 * Geprüft wird deshalb dreierlei: dass ein Arbeitsschritt alles auf einer
 * Seite verlangt, dass der Vordruck seine drei Teile in der Reihenfolge des
 * Papiers zeigt, und dass die Felder heißen, wie die Ausfüllanleitung sie
 * nennt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';

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
require_once $root . '/app/permission_mode.php';
require_once $root . '/4fach/official_message_form.php';

final class PaperImageFixture
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

$source = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($source)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
$renderStart = strpos($source, 'function plot_official_message_form()');
$assert(
    $renderStart !== false,
    estab_ux_requirement(
        'UX-PAPIERBILD',
        'Der Vordruck wird nicht mehr von plot_official_message_form() gesetzt.'
    )
);
$render = substr($source, (int) $renderStart);

/* --- Eine Seite: ein Formular, kein Weiterblättern --- */

$assert(
    substr_count($render, '<form method="post"') === 1,
    estab_ux_requirement(
        'UX-EINE-SEITE',
        'Der Vordruck besteht aus mehr oder weniger als einem Formular; '
            . 'Ausfüllen und Absenden lägen dann auseinander.'
    )
);
foreach (['<iframe', 'estab-wizard', 'data-estab-step', 'name="schritt"'] as $break) {
    $assert(
        !str_contains($render, $break),
        estab_ux_requirement(
            'UX-EINE-SEITE',
            'Der Vordruck enthält „' . $break . '“ und verteilt das '
                . 'Ausfüllen damit auf mehrere Schritte.'
        )
    );
}

/*
 * Die Seite besteht nicht aus einer einzigen Funktion. Sie ist eine Seite,
 * weil plot_official_message_form() die übrigen Teile selbst ausgibt, statt
 * auf sie zu verweisen. Ohne diesen Nachweis prüfte der folgende Abschnitt
 * nur, dass ein Feld irgendwo in der Datei steht.
 */
foreach (
    [
        'official_message_workflow_controls',
        'official_message_actions',
        'official_message_distribution',
        'official_message_priority',
        'official_message_attachments',
    ] as $part
) {
    $assert(
        str_contains($render, '$this->' . $part . '('),
        estab_ux_requirement(
            'UX-EINE-SEITE',
            'Der Teil ' . $part . ' wird nicht aus dem Vordruck heraus '
                . 'ausgegeben; er läge auf einer eigenen Seite.'
        )
    );
}

/*
 * Und alles, was ein Arbeitsschritt verlangt, steht in dieser einen Seite.
 * Ein Pflichtfeld ohne Bedienelement wäre eine Forderung ohne Ort: Die
 * Prüfung wiese die Nachricht zurück, und niemand fände, woran es liegt.
 */
$tasks = [
    'FM-Eingang', 'FM-Eingang_Anhang', 'FM-Ausgang',
    'LdF-Eingang', 'LdF-Ausgang',
    'Stab_schreiben', 'Stab_korrigieren', 'Stab_gesprnoti', 'Stab_sichten',
];
foreach ($tasks as $task) {
    foreach (['E', 'A'] as $direction) {
        $fixture = new PaperImageFixture();
        $fixture->task = $task;
        $fixture->formdata = ['04_richtung' => $direction];
        foreach ($fixture->official_message_required_fields() as $field) {
            $anchor = $fixture->official_message_field_anchor($field);
            $assert(
                str_contains($source, 'id="' . $anchor . '"')
                    || str_contains($source, "'" . $anchor . "'")
                    || str_contains($source, "name=\"" . $field . "\"")
                    || str_contains($source, "'" . $field . "'"),
                estab_ux_requirement(
                    'UX-EINE-SEITE',
                    'Der Arbeitsschritt ' . $task . ' (' . $direction
                        . ') verlangt ' . $field
                        . ', bietet dafür aber kein Feld auf der Seite an.'
                )
            );
        }
    }
}

/* --- Die drei Teile des Papiers, in der Reihenfolge des Papiers --- */

preg_match_all(
    '~data-estab-form-zone="([a-z-]+)"~',
    $render,
    $zoneMatches
);
$assert(
    $zoneMatches[1] === ['fm-zentrale', 'nachricht', 'sichter'],
    estab_ux_requirement(
        'UX-PAPIERBILD',
        'Der Vordruck zeigt die Teile ' . implode(', ', $zoneMatches[1])
            . ' statt oben die Vermerke der Fernmeldezentrale, in der Mitte '
            . 'die Nachricht und unten den Laufzettel.'
    )
);
$assert(
    str_contains($render, 'data-estab-form-zones="3"'),
    estab_ux_requirement(
        'UX-PAPIERBILD',
        'Der Vordruck weist seine Dreiteilung nicht aus.'
    )
);
foreach (['Fm-Zentrale', 'Sichter'] as $title) {
    $assert(
        str_contains($render, '>' . $title . '</h2>'),
        estab_ux_requirement(
            'UX-PAPIERBILD',
            'Der Teil „' . $title . '“ trägt keine Überschrift; auf Papier '
                . 'steht sie am Rand der Spalte.'
        )
    );
}

/*
 * Innerhalb der Teile folgt die Maske der Feldfolge des Papiers. Der
 * Nachweis der Nummernfolge selbst liegt in official_message_field_numbering;
 * hier zählt, dass die drei Teile die Nummern führen, die auf dem Papier in
 * ihnen stehen.
 */
$zoneNumbers = static function (string $render, string $zone, ?string $next): array {
    $start = strpos($render, 'data-estab-form-zone="' . $zone . '"');
    $end = $next === null
        ? strlen($render)
        : (int) strpos($render, 'data-estab-form-zone="' . $next . '"');
    if ($start === false) {
        return [];
    }
    $block = substr($render, $start, $end - $start);
    preg_match_all(
        '~estab-official-print-number">(\d+)<'
            . '|official_message_timestamp_block\(\s*\'[^\']*\',\s*(\d+),~',
        $block,
        $hits,
        PREG_SET_ORDER
    );
    $numbers = [];
    foreach ($hits as $hit) {
        $numbers[] = (int) ($hit[1] !== '' ? $hit[1] : $hit[2]);
    }
    sort($numbers, SORT_NUMERIC);
    return $numbers;
};

$expectedZones = [
    'fm-zentrale' => [[1, 2, 3, 4, 5, 6], 'nachricht'],
    'nachricht' => [[7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17], 'sichter'],
    'sichter' => [[18, 19, 20], null],
];
foreach ($expectedZones as $zone => [$expected, $next]) {
    $numbers = $zoneNumbers($render, $zone, $next);
    $assert(
        $numbers === $expected,
        estab_ux_requirement(
            'UX-PAPIERBILD',
            'Der Teil „' . $zone . '“ führt die Felder '
                . implode(', ', array_map('strval', $numbers)) . ' statt '
                . implode(', ', array_map('strval', $expected)) . '.'
        )
    );
}

/* --- Und die Felder heißen, wie die Ausfüllanleitung sie nennt --- */

/*
 * Die Prüfliste steht hier und nicht im Anwendungscode: Sie ist ein Auszug
 * aus der Ausfüllanleitung, gegen den die Anwendung gehalten wird. Stünde
 * sie im Code, prüfte die Anwendung sich gegen sich selbst.
 */
$officialNames = [
    '01_medium' => [1, 'Übermittlungsmittel'],
    '01_datum' => [2, 'Aufnahmevermerk, Zeit'],
    '01_zeichen' => [2, 'Aufnahmevermerk, Zeichen'],
    '02_zeit' => [3, 'Annahmevermerk, Zeit'],
    '02_zeichen' => [3, 'Annahmevermerk, Zeichen'],
    '03_datum' => [4, 'Beförderungsvermerk, Zeit'],
    '03_zeichen' => [4, 'Beförderungsvermerk, Zeichen'],
    '05_gegenstelle' => [6, 'Rufname der Gegenstelle'],
    '06_befwegausw' => [7, 'Gewünschtes Übermittlungsmittel'],
    '09_vorrangstufe' => [9, 'Vorrangstufe'],
    '10_anschrift' => [10, 'Anschrift'],
    '11_rufnummer' => [11, 'Ruf Nr.'],
    '12_betreff' => [13, 'Inhalt, Betreff'],
    '12_inhalt' => [14, 'Nachricht, Text'],
    '13_abseinheit' => [15, 'Absender'],
    '12_abfzeit' => [16, 'Abfassungszeit'],
    '14_zeichen' => [17, 'Zeichen des Verfassers'],
    '14_funktion' => [17, 'Funktion des Verfassers'],
    '15_quitdatum' => [18, 'Quittung, Zeit'],
    '15_quitzeichen' => [18, 'Quittung, Zeichen'],
    '16_empf' => [19, 'Verteiler'],
    '17_vermerke' => [20, 'Vermerke'],
];

$guidance = (new PaperImageFixture())->official_message_field_guidance();
foreach ($officialNames as $field => [$number, $name]) {
    $assert(
        isset($guidance[$field]),
        estab_ux_requirement(
            'UX-SPRACHE-VORSCHRIFT',
            'Das Feld ' . $field . ' der Ausfüllanleitung kommt in der '
                . 'Maske nicht vor.'
        )
    );
    $assert(
        ($guidance[$field]['number'] ?? null) === $number,
        estab_ux_requirement(
            'UX-SPRACHE-VORSCHRIFT',
            'Das Feld ' . $field . ' trägt die Nummer '
                . var_export($guidance[$field]['number'] ?? null, true)
                . ' statt ' . $number . '.'
        )
    );
    $assert(
        ($guidance[$field]['label'] ?? null) === $name,
        estab_ux_requirement(
            'UX-SPRACHE-VORSCHRIFT',
            'Das Feld ' . $field . ' heißt „'
                . (string) ($guidance[$field]['label'] ?? '')
                . '“ statt „' . $name . '“, wie die Ausfüllanleitung es nennt.'
        )
    );
}

// Kein Feld des amtlichen Rasters fehlt in der Prüfliste, und keines trägt
// eine Nummer, die der Vordruck nicht kennt.
foreach ($guidance as $field => $entry) {
    $number = (int) ($entry['number'] ?? 0);
    if ($number === 0) {
        // Betriebsfelder der Anwendung ausserhalb des amtlichen Rasters.
        continue;
    }
    $assert(
        isset($officialNames[$field]),
        estab_ux_requirement(
            'UX-SPRACHE-VORSCHRIFT',
            'Die Maske führt das Feld ' . $field . ' als Feld ' . $number
                . ' des Vordrucks, die Ausfüllanleitung kennt es dort nicht.'
        )
    );
}

printf("Papierbild des Vordrucks: OK (%d assertions)\n", $assertions);
