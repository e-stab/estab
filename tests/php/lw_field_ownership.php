<?php

declare(strict_types=1);

/**
 * "Nur den blauen Teil ausfüllen!" -- und was eine Rückweisung nicht ändert.
 *
 * Der Vordruck ist zweigeteilt. Die Felder 1 bis 5 gehören dem
 * Fernmeldebetrieb: Sie halten fest, wie eine Nachricht tatsächlich gelaufen
 * ist. Der Stab füllt den Nachrichtenteil. Diese Trennung ist der Grund,
 * warum der Vordruck als Nachweis taugt -- wer eine Nachricht schreibt, kann
 * nicht zugleich beurkunden, dass sie befördert wurde.
 *
 * Die Rückweisung prüft dieselbe Trennung von der anderen Seite: Eine
 * zurückgewiesene Nachricht geht an ihren Verfasser zurück und bleibt dabei
 * dieselbe Nachricht. Könnte der Verfasser sie bei der Korrektur in eine
 * Gesprächsnotiz umwidmen, verschwände die Rückweisung samt Anlass aus dem
 * Laufweg.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/message_status.php';
require_once $root . '/app/workflow.php';
require_once $root . '/app/function_label.php';
require_once $root . '/4fach/vali_data.php';

$previousDirectory = getcwd();
if (!chdir($root . '/4fach')) {
    throw new RuntimeException('Could not enter the message form directory');
}
try {
    require_once $root . '/4fach/4fachform.php';
} finally {
    if (is_string($previousDirectory)) {
        chdir($previousDirectory);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Ein leeres Empfängerraster; die Feldhoheit hängt nicht daran. */
$matrix = [];
for ($row = 1; $row <= 5; $row++) {
    for ($column = 1; $column <= 4; $column++) {
        $matrix[$row][$column] = [
            'typ' => 't', 'fkt' => '', 'rolle' => 'leer',
            'mode' => 'ro', 'auto' => 'f',
        ];
    }
}

/** Die Feldhoheit eines Arbeitsschritts, so wie der Controller sie herstellt. */
$ownership = static function (string $task, string $direction) use ($matrix): array {
    $form = (new ReflectionClass(nachrichten4fach::class))
        ->newInstanceWithoutConstructor();
    $form->task = $task;
    $form->formdata = [
        '04_richtung' => $direction,
        '16_empf' => '',
        '12_anhang' => '',
    ];
    $form->empfarray = $matrix;
    $form->feldbgcolor();
    $form->get_access_by_task();
    $bits = [];
    for ($i = 1; $i <= 17; $i++) {
        $bits[$i] = (bool)($form->feld[$i] ?? false);
    }
    return $bits;
};

/*
 * Der blaue Teil in Feldbits. Die Unterlage zählt den Vordruck anders als die
 * Ausfüllanleitung; die Feldhoheit folgt der Zählung der Unterlage. Feldbit 1
 * trägt Übermittlungsmittel und Aufnahmevermerk gemeinsam.
 */
$blueParts = [
    1 => 'Übermittlungsmittel und Aufnahmevermerk (Felder 1 und 2)',
    2 => 'Annahmevermerk (Feld 3)',
    3 => 'Beförderungsvermerk (Feld 4)',
    4 => 'Nummer des Technischen Betriebsbuchs (Feld 5)',
];

/* --- Der Stab schreibt, korrigiert und sichtet -- ohne den blauen Teil --- */

foreach (['Stab_schreiben', 'Stab_korrigieren', 'Stab_sichten', 'Stab_lesen'] as $task) {
    $bits = $ownership($task, 'A');
    foreach ($blueParts as $bit => $what) {
        $assert(
            $bits[$bit] === false,
            estab_dv_requirement(
                'LW-NUR-BLAUER-TEIL',
                'Der Arbeitsschritt ' . $task . ' erhält ' . $what
                    . ' zur Eingabe. Diese Felder gehören dem Fernmeldebetrieb.'
            )
        );
    }
}

/*
 * Eine Gesprächsnotiz hält ein selbst geführtes Gespräch fest. Dabei gibt es
 * keine Fernmeldezentrale, die das verwendete Mittel bezeugen könnte -- der
 * Verfasser trägt es selbst ein. Die Vermerke über Annahme und Beförderung
 * bleiben ihm auch dort verschlossen: befördert wurde nichts.
 */
$note = $ownership('Stab_gesprnoti', 'E');
$assert(
    $note[1] === true,
    estab_dv_requirement(
        'LW-NUR-BLAUER-TEIL',
        'Die Gesprächsnotiz kann das tatsächlich verwendete '
            . 'Übermittlungsmittel nicht festhalten.'
    )
);
foreach ([2, 3, 4] as $bit) {
    $assert(
        $note[$bit] === false,
        estab_dv_requirement(
            'LW-NUR-BLAUER-TEIL',
            'Die Gesprächsnotiz erhält ' . $blueParts[$bit]
                . ', obwohl sie nicht befördert wird.'
        )
    );
}

/* --- Gegenprobe: Der Fernmeldebetrieb hat den blauen Teil wirklich --- */

$reception = $ownership('FM-Eingang', 'E');
$assert(
    $reception[1] === true,
    estab_dv_requirement(
        'LW-NUR-BLAUER-TEIL',
        'Die Fernmeldezentrale kann den Aufnahmevermerk nicht setzen.'
    )
);
$acceptance = $ownership('LdF-Eingang', 'E');
$assert(
    $acceptance[2] === true,
    estab_dv_requirement(
        'LW-NUR-BLAUER-TEIL',
        'Der Leiter des Fernmeldebetriebes kann den Annahmevermerk nicht setzen.'
    )
);
$forwarding = $ownership('FM-Ausgang', 'A');
$assert(
    $forwarding[3] === true,
    estab_dv_requirement(
        'LW-NUR-BLAUER-TEIL',
        'Die Fernmeldezentrale kann den Beförderungsvermerk nicht setzen.'
    )
);

// Die Nummer des Technischen Betriebsbuchs vergibt die Anwendung, nicht der
// Anwender. Kein Arbeitsschritt gibt sie zur Eingabe frei.
foreach (
    [
        ['FM-Eingang', 'E'], ['FM-Eingang_Anhang', 'E'], ['FM-Ausgang', 'A'],
        ['LdF-Eingang', 'E'], ['LdF-Ausgang', 'A'], ['Stab_gesprnoti', 'E'],
    ] as [$task, $direction]
) {
    $assert(
        $ownership($task, $direction)[4] === false,
        estab_dv_requirement(
            'LW-NUR-BLAUER-TEIL',
            'Der Arbeitsschritt ' . $task . ' darf die Nummer des '
                . 'Technischen Betriebsbuchs von Hand setzen.'
        )
    );
}

/* ------------------------------------------------- *
 * Die Korrekturschleife: zurück, aber dieselbe Sache *
 * ------------------------------------------------- */

$transitions = estab_message_status_transitions();
$step = static function (
    string $direction,
    int $from,
    int $to,
    string $station
) use ($transitions): bool {
    foreach ($transitions[$direction] ?? [] as $candidate) {
        if (
            ($candidate['from'] ?? null) === $from
            && ($candidate['to'] ?? null) === $to
            && ($candidate['station'] ?? null) === $station
        ) {
            return true;
        }
    }
    return false;
};

// Zurückweisen kann, wer prüft: der Sichter und der Leiter des
// Fernmeldebetriebes. Beide Wege enden im selben Stand.
foreach (
    [
        [ESTAB_MESSAGE_STATUS_REVIEW, 'Si', 'Der Sichter'],
        [ESTAB_MESSAGE_STATUS_LDF, 'LdF', 'Der Leiter des Fernmeldebetriebes'],
    ] as [$from, $station, $who]
) {
    $assert(
        $step('outgoing', $from, ESTAB_MESSAGE_STATUS_RETURNED, $station),
        estab_dv_requirement(
            'LW-KORREKTURSCHLEIFE',
            $who . ' kann eine ausgehende Nachricht nicht zurückweisen.'
        )
    );
}

// Und sie kehrt zu ihrem Verfasser zurück -- nur dorthin.
foreach (['outgoing', 'conversation-note'] as $direction) {
    $assert(
        $step(
            $direction,
            ESTAB_MESSAGE_STATUS_RETURNED,
            ESTAB_MESSAGE_STATUS_REVIEW,
            'Verfasser'
        ),
        estab_dv_requirement(
            'LW-KORREKTURSCHLEIFE',
            'Eine zurückgewiesene Nachricht (' . $direction
                . ') findet nicht zu ihrem Verfasser zurück.'
        )
    );
    $targets = [];
    foreach ($transitions[$direction] ?? [] as $candidate) {
        if (($candidate['from'] ?? null) === ESTAB_MESSAGE_STATUS_RETURNED) {
            $targets[] = $candidate['to'];
        }
    }
    $assert(
        array_values(array_unique($targets)) === [ESTAB_MESSAGE_STATUS_REVIEW],
        estab_dv_requirement(
            'LW-KORREKTURSCHLEIFE',
            'Der Stand "Zur Korrektur" (' . $direction . ') führt außer zum '
                . 'Verfasser noch woanders hin.'
        )
    );
}

/*
 * Dieselbe Nachricht: Die Korrektur gibt genau die Felder frei, die auch das
 * Schreiben freigibt -- mit einer Ausnahme. Die Gesprächsnotiz bleibt
 * verschlossen, sonst würde aus der zurückgewiesenen ausgehenden Nachricht
 * ein anderer Gegenstand.
 */
$writing = $ownership('Stab_schreiben', 'A');
$correcting = $ownership('Stab_korrigieren', 'A');
$differences = [];
for ($bit = 1; $bit <= 17; $bit++) {
    if ($writing[$bit] !== $correcting[$bit]) {
        $differences[] = $bit;
    }
}
$assert(
    $differences === [11],
    estab_dv_requirement(
        'LW-KORREKTURSCHLEIFE',
        'Die Korrektur arbeitet an anderen Feldern als das Schreiben: '
            . implode(', ', $differences)
    )
);
$assert(
    $writing[11] === true && $correcting[11] === false,
    estab_dv_requirement(
        'LW-KORREKTURSCHLEIFE',
        'Der Verfasser kann seine zurückgewiesene Nachricht bei der '
            . 'Korrektur in eine Gesprächsnotiz umwidmen.'
    )
);

printf("Feldhoheit und Korrekturschleife: OK (%d assertions)\n", $assertions);
