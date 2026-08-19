<?php

declare(strict_types=1);

/**
 * Eine abgesetzte Nachricht muss sich melden, und eine zurueckgewiesene muss
 * einen Weg zurueck haben.
 *
 * Der Nachrichtenrahmen beantwortete ein erfolgreiches Absenden mit einem
 * leeren Dokument, das beide Rahmen neu lud: der Bedienende erfuhr weder, ob
 * gespeichert wurde, noch an welche Station die Nachricht gegangen ist. Und
 * die Korrekturschleife besass zwar Route, Renderer und Liste, aber kein
 * Bedienelement, das dorthin fuehrte. Dieser Test haelt beides fest: jede
 * abgeschlossene Aktion benennt Vorgang und Ziel, die Bestaetigung bleibt im
 * Nachrichtenrahmen stehen, und die Schaltfläche der Korrekturschleife
 * erscheint genau dann, wenn fuer eine getragene Funktion wirklich etwas
 * zurueckliegt - gemessen im selben Datenbankumlauf wie die Warteschlangen.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/session_ui.php';
require_once $root . '/app/sidebar.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertThrows = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (InvalidArgumentException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

/* ---------------------------------------------------------------------------
   1. Jede abgeschlossene Aktion benennt Vorgang und Zielstation.
   ------------------------------------------------------------------------ */

$outcome = static fn (
    string $task,
    array $request = [],
    string $direction = '',
    string $function = ''
): ?array => estab_session_ui_message_outcome(
    $task,
    $request,
    $direction,
    $function
);

$expectations = [
    // Aufgabe, Schalter, Richtung => erwartete Zielstation
    ['Stab_schreiben', [], '', 'An Sichter übergeben', 'forwarded'],
    ['Stab_korrigieren', [], '', 'Erneut an Sichter übergeben', 'forwarded'],
    ['Stab_gesprnoti', [], '', 'An Sichter übergeben', 'forwarded'],
    ['FM-Eingang', [], '', 'An LdF zur Annahme übergeben', 'forwarded'],
    ['FM-Eingang_Anhang', [], '', 'An LdF zur Annahme übergeben', 'forwarded'],
    ['LdF-Eingang', [], '', 'An Sichter übergeben', 'forwarded'],
    ['LdF-Ausgang', [], '', 'Zur Beförderung an A/W', 'forwarded'],
    ['FM-Ausgang', [], '', 'Vorgang abgeschlossen', 'completed'],
    [
        'Stab_sichten',
        [],
        'E',
        'An die Stabsfunktionen verteilt',
        'completed',
    ],
    [
        'Stab_sichten',
        [],
        'A',
        'An LdF zur Disposition übergeben',
        'forwarded',
    ],
];
foreach ($expectations as [$task, $request, $direction, $target, $tone]) {
    $result = $outcome($task, $request, $direction);
    $assert(
        is_array($result)
            && $result['destination'] === $target
            && $result['tone'] === $tone
            && trim($result['title']) !== ''
            && trim($result['detail']) !== '',
        'Die Aktion ' . $task . ' benennt ihre Zielstation nicht als "'
            . $target . '"'
    );
}

/*
 * Eine Rueckgabe ist kein Erfolg im selben Sinn: sie muss den Weg zurueck
 * benennen, damit der Verfasser weiss, dass die Nachricht bei ihm liegt.
 */
$returns = [
    ['Stab_sichten', ['zurueckweisen_x' => '1'], 'A'],
    ['Stab_sichten', ['zurueckweisen_y' => '4'], 'A'],
    ['LdF-Ausgang', ['ldf_zurueckweisen_x' => '1'], 'A'],
    ['LdF-Ausgang', ['ldf_zurueckweisen_y' => '4'], 'A'],
];
foreach ($returns as [$task, $request, $direction]) {
    $result = $outcome($task, $request, $direction);
    $assert(
        is_array($result)
            && $result['tone'] === 'returned'
            && str_contains($result['destination'], 'Korrekturschleife'),
        'Die Rueckgabe aus ' . $task . ' benennt die Korrekturschleife nicht'
    );
}
$transportReturn = $outcome(
    'FM-Ausgang',
    ['transport_nicht_moeglich_x' => '1'],
    'A'
);
$assert(
    is_array($transportReturn)
        && $transportReturn['tone'] === 'returned'
        && $transportReturn['destination'] === 'An LdF zurückgegeben',
    'Eine unmoegliche Befoerderung meldet nicht die Rueckgabe an den LdF'
);

/*
 * Ohne bekannte Aufgabe wird nichts behauptet. Eine erfundene Bestaetigung
 * waere schlimmer als gar keine.
 */
$assert(
    $outcome('') === null
        && $outcome('Stab_lesen') === null
        && $outcome('<script>') === null,
    'Eine unbekannte Aufgabe erzeugt eine erfundene Bestaetigung'
);

/*
 * Die Fortsetzung fuehrt zurueck in die eigene Funktion. Nur die vom Server
 * aufgeloeste Funktion darf sie tragen; ein Browserwert nie.
 */
$staffOutcome = $outcome('Stab_schreiben', [], '', 'S2');
$assert(
    is_array($staffOutcome)
        && $staffOutcome['acting_function'] === 'S2'
        && array_column($staffOutcome['actions'], 'name') === [
            'stab_schreiben_x',
            'stab_lesen_x',
        ],
    'Die Rueckmeldung fuehrt nicht in die eigene Stabsfunktion weiter'
);
$forged = $outcome('Stab_schreiben', [], '', 'S2; DROP');
$empty = $outcome('Stab_schreiben', [], '', '');
$foreign = $outcome('LdF-Ausgang', [], '', 'S2');
$assert(
    is_array($forged) && $forged['acting_function'] === null
        && is_array($empty) && $empty['acting_function'] === null
        && is_array($foreign) && $foreign['acting_function'] === null,
    'Eine ungueltige oder fremde Funktion erreicht das Formular'
);

/* ---------------------------------------------------------------------------
   2. Die Bestaetigung ist eine Aussage, kein Formularschlupfloch.
   ------------------------------------------------------------------------ */

$assert(
    estab_session_ui_message_confirmation_markup(null, '/4fach/mainindex.php')
        === '',
    'Ohne Rueckmeldung entsteht trotzdem Bestaetigungs-Markup'
);
foreach (
    [
        ['tone' => 'unbekannt'] + $staffOutcome,
        ['title' => ''] + $staffOutcome,
        ['destination' => 123] + $staffOutcome,
        ['acting_function' => 'S2"><script>'] + $staffOutcome,
        ['actions' => [['name' => 'evil', 'label' => 'x']]] + $staffOutcome,
        ['actions' => [['name' => 'stab_lesen_x', 'label' => '']]]
            + $staffOutcome,
        ['actions' => 'stab_lesen_x'] + $staffOutcome,
    ] as $broken
) {
    $assertThrows(
        static fn (): string => estab_session_ui_message_confirmation_markup(
            $broken,
            '/4fach/mainindex.php'
        ),
        'Eine unsaubere Rueckmeldung wird gerendert statt abgewiesen'
    );
}
$assertThrows(
    static fn (): string => estab_session_ui_message_confirmation_markup(
        $staffOutcome,
        "/4fach/mainindex.php\n"
    ),
    'Eine Zieladresse mit Steuerzeichen wird uebernommen'
);

session_id('estab-message-feedback-test');
session_start();
$markup = estab_session_ui_message_confirmation_markup(
    $staffOutcome,
    '/4fach/mainindex.php'
);
$assert(
    str_contains($markup, 'data-estab-message-confirmation="forwarded"')
        && str_contains($markup, 'An Sichter übergeben')
        && str_contains($markup, 'role="status"')
        && str_contains($markup, 'estab-tool-feedback-success')
        && substr_count($markup, 'name="csrf_token"') === 1
        && substr_count($markup, 'name="acting_function" value="S2"') === 1
        && substr_count($markup, 'name="stab_schreiben_x"') === 1
        && substr_count($markup, 'name="stab_lesen_x"') === 1
        // Der geuebte Bedienende erreicht den naechsten Griff ohne Maus.
        && substr_count($markup, 'autofocus') === 1
        && str_contains(
            $markup,
            'estab-button-primary" autofocus type="submit"'
        ),
    'Die Bestaetigung nennt Ziel und naechsten Griff nicht vollstaendig'
);
$returnedMarkup = estab_session_ui_message_confirmation_markup(
    $outcome('Stab_sichten', ['zurueckweisen_x' => '1'], 'A'),
    '/4fach/mainindex.php'
);
$assert(
    str_contains($returnedMarkup, 'data-estab-message-confirmation="returned"')
        && str_contains($returnedMarkup, 'estab-tool-feedback-warning')
        && !str_contains($returnedMarkup, 'name="acting_function"'),
    'Eine Rueckgabe sieht aus wie ein gelungener Versand'
);

/*
 * Nur die Seitenleiste wird aufgefrischt. Wer auch den Nachrichtenrahmen neu
 * laedt, loescht die Aussage, bevor sie gelesen werden kann.
 */
$sidebarRefresh = estab_session_ui_sidebar_refresh_script();
$assert(
    str_starts_with($sidebarRefresh, 'FramesVeraendern(')
        && str_contains($sidebarRefresh, '"vorgaben"')
        && !str_contains($sidebarRefresh, '"mainframe"'),
    'Die Bestaetigung wird durch eine Auffrischung des Rahmens ueberschrieben'
);

$controller = file_get_contents($root . '/4fach/mainindex.php');
$assert(
    is_string($controller)
        && substr_count($controller, 'estab_session_ui_message_outcome (') === 1
        && substr_count(
            $controller,
            'estab_session_ui_message_confirmation_markup ('
        ) === 1
        && substr_count(
            $controller,
            'estab_session_ui_sidebar_refresh_script ()'
        ) === 1,
    'Der Nachrichtenrahmen meldet den Ausgang der Aktion nicht zurueck'
);

/*
 * Die Nachricht ist beim Rendern der Tafel bereits gespeichert. Scheitert
 * allein die Tafel, darf sie die Antwort der abgeschlossenen Aktion nicht
 * zerstoeren, sondern muss auf die historische Auffrischung zurueckfallen.
 */
$assert(
    is_string($controller)
        && preg_match(
            '~function resetframeset \(\$confirmation = null\) \{~',
            $controller
        ) === 1
        && preg_match(
            '~estab_session_ui_message_confirmation_markup \([^;]*?\);\s*'
                . '\} catch \(Throwable~s',
            $controller
        ) === 1
        && substr_count(
            $controller,
            'estab_session_ui_frame_refresh_script ()'
        ) === 1,
    'Eine gescheiterte Bestaetigungstafel reisst die Antwort einer bereits '
        . 'gespeicherten Nachricht mit'
);

/* ---------------------------------------------------------------------------
   3. Die Korrekturschleife bekommt einen Einstieg mit Zaehler.
   ------------------------------------------------------------------------ */

$doubleHat = [
    'benutzer' => 'Müller, Ada',
    'kuerzel' => 'ada001',
    'funktion' => 'S1',
    'rolle' => 'Stab',
    'estab_permission_mode' => 'LOOSE',
    'estab_additional_functions' => [
        ['funktion' => 'S2', 'rolle' => 'Stab'],
        ['funktion' => 'LdF', 'rolle' => 'Fernmelder'],
    ],
];
$correctionProfiles = estab_sidebar_correction_profiles($doubleHat);
$assert(
    array_column($correctionProfiles, 'funktion') === ['S1', 'S2']
        && array_column($correctionProfiles, 'baseline_key') === [
            'old_que_korr_s1',
            'old_que_korr_s2',
        ]
        && array_column($correctionProfiles, 'session_key') === [
            'old_que_korr',
            'old_que_korr',
        ],
    'Nicht jede getragene Stabsfunktion bekommt ihre eigene Korrekturzaehlung'
);
$assert(
    estab_sidebar_correction_profiles(null) === []
        && estab_sidebar_correction_profiles([
            'funktion' => 'LdF',
            'rolle' => 'Fernmelder',
        ]) === []
        && estab_sidebar_correction_profiles([
            'funktion' => 'Si',
            'rolle' => 'Stab',
        ]) === [],
    'Eine Funktion ohne eigenen Meldungseingang erhaelt eine Korrekturschleife'
);

/*
 * Der Zaehler entsteht in derselben Abfrage wie die Warteschlangen. Ein
 * zweiter Umlauf je Seitenleistenaufbau waere reine Verdopplung.
 */
$queueProfiles = estab_sidebar_queue_profiles($doubleHat);
$batch = estab_sidebar_queue_batch_query(
    array_merge($queueProfiles, $correctionProfiles),
    'nv_nachrichten',
    'usr_',
    false,
    42
);
$assert(
    str_starts_with($batch['sql'], 'SELECT ')
        && in_array('old_que_korr_s1', $batch['keys'], true)
        && in_array('old_que_korr_s2', $batch['keys'], true)
        && substr_count($batch['sql'], '`x00_status` = 10') === 2,
    'Die Korrekturzaehler werden nicht im vorhandenen Stapel mitgemessen'
);
$correctionQuery = estab_sidebar_queue_query(
    'old_que_korr',
    'nv_nachrichten',
    'usr_',
    'S1',
    false,
    42
);
/*
 * Der Funktionsvergleich muss BINARY sein: estab_message_object_allowed()
 * filtert die Liste anschliessend mit einem exakten PHP-Vergleich. Ein loser
 * Kollationsvergleich meldete am Knopf mehr, als die Liste zeigt.
 */
$assert(
    $correctionQuery['parameters'] === [42, 'S1']
        && str_contains($correctionQuery['sql'], '`x00_status` = 10')
        && str_contains($correctionQuery['sql'], "`04_richtung` = 'A'")
        && str_contains(
            $correctionQuery['sql'],
            'BINARY `14_funktion` = BINARY ?'
        )
        && str_contains($correctionQuery['sql'], "`x01_abschluss` = 'f'")
        && !str_contains($correctionQuery['sql'], 'S1'),
    'Die Korrekturabfrage bindet Einsatz und Funktion nicht exakt als '
        . 'Parameter'
);
$assertThrows(
    static fn (): array => estab_sidebar_queue_query(
        'old_que_korr',
        'nv_nachrichten',
        'usr_',
        'S1; DROP',
        false,
        42
    ),
    'Ein ungueltiger Funktionsname erreicht die Korrekturabfrage'
);
$assert(
    estab_sidebar_queue_baseline_key('old_que_korr_s1') === 'old_que_korr_s1',
    'Der Korrekturschluessel gilt nicht als gueltige Warteschlange'
);

/*
 * Die Schaltfläche erscheint nur mit Arbeit dahinter, traegt den Zaehler und
 * fuehrt auf die bestehende Route der zurueckgewiesenen Meldungen.
 */
$keys = static fn (array $actions): array => array_column($actions, 'key');
$assert(
    !in_array(
        'stab_korrekturen',
        $keys(estab_sidebar_workflow_actions($doubleHat, 'ROLLE')),
        true
    )
        && !in_array(
            'stab_korrekturen',
            $keys(estab_sidebar_workflow_actions(
                $doubleHat,
                'ROLLE',
                ['S1' => 0, 'S2' => 0]
            )),
            true
        ),
    'Die Korrekturschleife erscheint ohne zurueckgewiesene Meldung'
);
$withCorrections = estab_sidebar_workflow_actions(
    $doubleHat,
    'ROLLE',
    ['S1' => 2, 'S2' => 1, 'S9' => 7]
);
$correctionAction = null;
foreach ($withCorrections as $action) {
    if ($action['key'] === 'stab_korrekturen') {
        $correctionAction = $action;
    }
}
$assert(
    is_array($correctionAction)
        && $correctionAction['name'] === 'stab_korrekturen_x'
        && $correctionAction['badge'] === '3'
        && str_contains($correctionAction['description'], '3 zurückgewiesene'),
    'Die Schaltfläche der Korrekturschleife zaehlt nicht die eigenen '
        . 'getragenen Funktionen'
);
$assert(
    count(array_filter(
        $keys($withCorrections),
        static fn (string $key): bool => $key === 'stab_korrekturen'
    )) === 1,
    'Zwei getragene Stabsfunktionen erzeugen zwei Korrektureinstiege'
);
$singleHat = estab_sidebar_workflow_actions(
    ['funktion' => 'S1', 'rolle' => 'Stab'],
    'ROLLE',
    ['S1' => 1]
);
$correctionSingle = null;
foreach ($singleHat as $action) {
    if ($action['key'] === 'stab_korrekturen') {
        $correctionSingle = $action;
    }
}
$assert(
    is_array($correctionSingle)
        && $correctionSingle['badge'] === '1'
        && str_contains($correctionSingle['description'], '1 zurückgewiesene '
            . 'Meldung'),
    'Eine einzelne Korrektur wird in der Mehrzahl angekuendigt'
);
$assert(
    !in_array(
        'stab_korrekturen',
        $keys(estab_sidebar_workflow_actions(
            ['funktion' => 'LdF', 'rolle' => 'Fernmelder'],
            'ROLLE',
            ['LdF' => 5, 'S1' => 5]
        )),
        true
    ),
    'Eine fremde Funktion oeffnet die Korrekturschleife eines Sachgebiets'
);

/*
 * Die Seitenleiste reicht die Messung an die Aktionsliste weiter, ohne einen
 * zweiten Datenbankumlauf zu eroeffnen. Beide Aufrufstellen werden
 * strukturell festgehalten: eine blosse Zaehlung des Bezeichners wird schon
 * von der Funktionssignatur allein erfuellt und beweist nichts.
 */
$navigation = (string) file_get_contents($root . '/4fach/vorgaben.php');
$assert(
    substr_count($navigation, 'estab_sidebar_queue_counts(') === 1
        && substr_count($navigation, 'estab_sidebar_correction_profiles(') === 1
        && preg_match_all(
            '~array_merge\(\$queueProfiles,\s*\$measuredCorrections\)~',
            $navigation
        ) === 1,
    'Die Seitenleiste misst die Korrekturschleife in einem zweiten '
        . 'Datenbankumlauf statt im vorhandenen Stapel'
);
$assert(
    preg_match_all(
        '~estab_vorgaben_status_markup\([^;]*\$correctionProfiles,'
            . '\s*\$correctionCounts\s*\)~s',
        $navigation
    ) === 1
        && preg_match_all(
            '~estab_sidebar_workflow_actions\(\s*\$selectedIdentity,'
                . '\s*\$menuState,\s*\$correctionCounts,?\s*\)~',
            $navigation
        ) === 1
        && str_contains($navigation, "\$action['badge']"),
    'Die gemessenen Korrekturen erreichen die Aktionsliste der Seitenleiste '
        . 'nicht'
);

$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(
    is_string($stylesheet)
        && str_contains($stylesheet, '.estab-sidebar-action-badge')
        && str_contains($stylesheet, '.estab-message-confirmation')
        && str_contains($stylesheet, '.estab-message-confirmation-actions'),
    'Die neuen Bedienelemente haben keine Regel im Stylesheet'
);

echo "message feedback loop: OK ({$assertions} assertions)\n";
