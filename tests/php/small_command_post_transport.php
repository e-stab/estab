<?php

declare(strict_types=1);

/**
 * Beförderung in der Führungsstelle ohne Sachgebiet S6.
 *
 * DV 1-101 kennt die Führungsstelle ohne Stab. Sie hat kein S6, also auch
 * keinen veröffentlichten Fernmeldeplan, aus dem LdF einen Weg auswählen
 * könnte. Ihre ausgehenden Nachrichten müssen trotzdem befördert werden:
 * LdF disponiert das Übermittlungsmittel in Feld 1 und benennt den
 * Beförderungsweg in Feld 6 unmittelbar. Der Nachweis darf dabei nicht
 * schwächer werden als mit Plan, und Feld 7 bleibt der Wunsch des
 * Verfassers, den nach dem Verfassen niemand mehr überschreibt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/permission_mode.php';
require_once $root . '/app/message_repository.php';
require_once $root . '/4fach/vali_data.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (
    &$assertions
): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$read = static function (string $path) use ($root): string {
    $source = file_get_contents($root . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Quelle nicht lesbar: ' . $path);
    }
    return $source;
};

$section = static function (
    string $source,
    string $start,
    string $end
): string {
    $from = strpos($source, $start);
    $to = $from === false ? false : strpos($source, $end, $from + 1);
    if ($from === false || $to === false) {
        throw new RuntimeException('Abschnitt nicht auffindbar: ' . $start);
    }
    return substr($source, $from, $to - $from);
};

// 1. Die Regel ist entscheidbar - und sie fällt ohne Kontext geschlossen aus.
$assert(
    function_exists('estab_permission_telecom_plan_required'),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Keine Regel entscheidet, ob ein veröffentlichter S6-Fernmeldeplan '
            . 'für die Beförderung zwingend ist'
    )
);
$assert(
    estab_permission_telecom_plan_required(),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Ohne Berechtigungskontext wird der Fernmeldeplan nicht mehr verlangt'
    )
);
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 42,
    'revision' => 7,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
]);
$assert(
    estab_permission_telecom_plan_required(),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Der Modus STRENG verlangt den veröffentlichten Fernmeldeplan nicht mehr'
    )
);
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 42,
    'revision' => 8,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_LOOSE,
]);
$assert(
    !estab_permission_telecom_plan_required(),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Die Führungsstelle ohne Stab kann ohne veröffentlichten '
            . 'Fernmeldeplan nicht befördern'
    )
);

// 2. Feld 7 bleibt der Wunsch: die Disposition des LdF landet in Feld 1.
$repository = $read('/app/message_repository.php');
$leadOutgoing = $section(
    $repository,
    "if (\$direction === 'A' && \$status === ESTAB_MESSAGE_STATUS_LDF)",
    "if (\$direction === 'A' && \$status === ESTAB_MESSAGE_STATUS_TRANSPORT)"
);
$assert(
    !str_contains($repository, "\$fields['06_befwegausw']"),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Die Disposition überschreibt weiterhin den Wunsch des Verfassers '
            . 'in Feld 7, statt in Feld 1 zu stehen'
    )
);
$assert(
    str_contains(
        $leadOutgoing,
        "\$fields['01_medium'] = (string) \$route['medium'];"
    )
        && str_contains($leadOutgoing, "\$fields['06_befweg'] = \$routeText;"),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Der aus dem Plan gewählte Weg setzt Mittel und Weg nicht in '
            . 'Feld 1 und Feld 6'
    )
);
$assert(
    str_contains(
        $leadOutgoing,
        "                if (\n"
            . "                    !\$routeSelected\n"
            . "                    && estab_permission_telecom_plan_required()\n"
            . "                ) {"
    )
        && str_contains(
            $leadOutgoing,
            'Ein Weg aus dem aktiven S6-Fernmeldeplan ist erforderlich.'
        ),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Der Plan wird entweder immer oder nie verlangt, statt am Modus zu '
            . 'entscheiden'
    )
);
$assert(
    substr_count($leadOutgoing, "\$event['snapshot']['transport_medium']") === 2
        && substr_count(
            $leadOutgoing,
            "\$event['snapshot']['transport_route']"
        ) === 2,
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Ohne Plan fehlen Mittel und Weg in der Ereigniskette; der Nachweis '
            . 'wäre schwächer als mit Plan'
    )
);
$planFreeBranch = $section(
    $leadOutgoing,
    "                } else {",
    "                if (\$redisposition) {"
);
$assert(
    str_contains($planFreeBranch, "\$fields['01_medium'] = \$disposedMedium;")
        && str_contains($planFreeBranch, "\$fields['06_befweg'] = \$routeText;")
        && str_contains($planFreeBranch, 'estab_message_medium_storage_value(')
        && str_contains($planFreeBranch, 'estab_message_single_line_value('),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Ohne Plan nimmt der Ausgang Mittel oder Weg ungeprüft entgegen'
    )
);
$assert(
    str_contains($planFreeBranch, 'if ($previousRouteEntryId > 0) {')
        && str_contains(
            $planFreeBranch,
            'Nach einer Disposition aus dem S6-Fernmeldeplan '
        ),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Eine bereits aus dem Plan disponierte Nachricht darf ihren '
            . 'Plan-Eintrag verlieren'
    )
);

// 3. Die A/W-Stufe hängt an der Disposition, nicht am Wunsch.
$stage = estab_message_operator_stage_predicate('A', 2);
$assert(
    !str_contains($stage, '06_befwegausw')
        && str_contains($stage, '`01_medium` <> ?')
        && str_contains($stage, '`06_befweg` <> ?'),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Die A/W-Stufe erkennt die Disposition weiter am Wunsch aus Feld 7, '
            . 'der schon beim Verfassen gefüllt ist'
    )
);
$assert(
    substr_count($stage, '?')
        === count(estab_message_operator_stage_parameters('A', 2)),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Platzhalter und Parameter der A/W-Stufe passen nicht zusammen'
    )
);

// 4. Vordruck, Prüfung und Übergabe lassen die Disposition ohne Plan zu.
$validatorSource = $read('/4fach/vali_data.php');
$leadOutgoingCase = $section(
    $validatorSource,
    'case "LdF-Ausgang":',
    'case "Stab_sichten":'
);
$assert(
    str_contains($leadOutgoingCase, 'estab_permission_telecom_plan_required ()')
        && str_contains($leadOutgoingCase, '$this->validate["01_medium"]')
        && str_contains($leadOutgoingCase, '$this->validate["06_befweg"]')
        && str_contains(
            $leadOutgoingCase,
            '$this->validate["fernmeldeplan_eintrag_id"]'
        ),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Die Prüfung des LdF-Ausgangs verlangt den Plan-Eintrag in jedem Modus'
    )
);
$validator = new vali_data_form([
    'task' => 'LdF-Ausgang',
    '01_medium' => 'Me',
    '06_befweg' => 'Melder zum Bereitstellungsraum Süd',
    'fernmeldeplan_eintrag_id' => '',
]);
$validator->checkallfields();
$assert(
    $validator->validate['01_medium'] === true
        && $validator->i_data['01_medium'] === 'Me'
        && $validator->validate['06_befweg'] === true
        && $validator->validate['fernmeldeplan_eintrag_id'] === false,
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Die unmittelbar benannte Disposition besteht die Feldprüfung nicht'
    )
);
$invalid = new vali_data_form([
    'task' => 'LdF-Ausgang',
    '01_medium' => 'Brieftaube',
    '06_befweg' => "Melder\nmit Zeilenumbruch",
]);
$invalid->checkallfields();
$assert(
    $invalid->validate['01_medium'] === false
        && $invalid->validate['06_befweg'] === false,
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Ohne Plan nimmt die Prüfung ein erfundenes Mittel oder einen '
            . 'mehrzeiligen Weg an'
    )
);
$controller = $read('/4fach/data_hndl.php');
$leadOutgoingBranch = $section(
    $controller,
    '        $ldfFields ["05_gegenstelle"] = trim (',
    '        $ldfFields ["x00_status"] = 2;'
);
$assert(
    str_contains(
        $leadOutgoingBranch,
        '$ldfFields ["01_medium"] = (string) $data ["01_medium"];'
    )
        && str_contains(
            $leadOutgoingBranch,
            '$ldfFields ["06_befweg"] = trim ((string) $data ["06_befweg"]);'
        )
        && str_contains(
            $leadOutgoingBranch,
            'estab_message_positive_id ($ldfRouteEntry)'
        ),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Der Ausgang übergibt ohne Plan weder Mittel noch Weg an den Nachweis'
    )
);
$assert(
    str_contains(
        $controller,
        '$routeValidation ["01_medium"] = false;'
    )
        && str_contains(
            $controller,
            '$routeValidation ["06_befweg"] = false;'
        ),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Eine abgewiesene Disposition ohne Plan markiert nur den '
            . 'Auswahlkasten, den es dort gar nicht gibt'
    )
);
$form = $read('/4fach/official_message_form.php');
$assert(
    str_contains(
        $form,
        "            || (\$this->task === 'LdF-Ausgang'\n"
            . "                && \$this->official_message_manual_disposition())"
    )
        && str_contains(
            $form,
            'Ohne veröffentlichten Fernmeldeplan disponieren Sie '
        )
        && str_contains($form, "'06_befweg',\n                    true,"),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Der Vordruck bietet dem LdF ohne Plan kein Feld für Mittel und Weg'
    )
);

/*
 * Das Feld erscheint genau dort, wo die Prüfung es annimmt: in der Betriebs-
 * art LOCKER, also der Führungsstelle ohne eigenes S6. In der Betriebsart
 * STRENG bleibt der Plan verbindlich, und die Maske sagt das, statt eine
 * Eingabe zu verlangen, die das Speichern anschliessend zurückweist.
 */
$assert(
    str_contains(
        $form,
        'return $this->activeTelecomRoutes === []'
    )
        && str_contains(
            $form,
            '&& !estab_permission_telecom_plan_required();'
        )
        && str_contains($form, 'estab-message-plan-blocked'),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Der Vordruck unterscheidet die Führungsstelle ohne S6 nicht von '
            . 'der strengen Betriebsart ohne freigegebenen Plan'
    )
);
$assert(
    str_contains(
        $form,
        "                . (\$this->activeTelecomRoutes === [] ? '' : ' required')"
    ),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Der leere Auswahlkasten des Fernmeldeplans bleibt ein Pflichtfeld '
            . 'und blockiert die Beförderung schon im Browser'
    )
);

// 5. Der Nachweis führt Mittel und Weg auch ohne Plan mit.
$logbook = $read('/app/logbook_lifecycle.php');
$assert(
    !str_contains($logbook, "\$message['06_befwegausw']")
        && str_contains(
            $logbook,
            "\$medium = trim((string) (\$message['01_medium'] ?? ''));"
        )
        && str_contains($logbook, "(string) (\$message['06_befweg'] ?? ''),"),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Das TBB weist den Wunsch statt des tatsächlich benutzten Weges nach'
    )
);

// 6. Scheitert die Beförderung, kehrt die Nachricht mit einem Vermerk zurück,
//    der das nicht erreichbare TK-Mittel nennt - ohne Plan ist dieser Rückweg
//    der einzige Weg zu einer neuen Disposition.
$transportStage = $section(
    $repository,
    "if (\$direction === 'A' && \$status === ESTAB_MESSAGE_STATUS_TRANSPORT)",
    '            $assignments = [];'
);
$assert(
    str_contains(
        $transportStage,
        "\$fields['17_vermerke'] = estab_message_note_with_entry("
    )
        && str_contains(
            $transportStage,
            'estab_message_medium_text($confirmedMedium)'
        )
        && str_contains($transportStage, 'nicht erreichbar.'),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Die Rückgabe an LdF nennt das nicht erreichbare TK-Mittel nicht, '
            . 'also fehlt der neuen Disposition ihre Begründung'
    )
);
$assert(
    str_contains(
        $transportStage,
        "\$confirmedMedium = trim(\n"
            . "                    (string) (\$mediumRow['01_medium'] ?? '')\n"
            . '                );'
    ),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'A/W liest das zu benutzende Mittel weiter aus dem Wunsch in Feld 7'
    )
);
$existingNote = 'Si: formal geprüft';
$mergedNote = estab_message_note_with_entry(
    $existingNote,
    'Rückgabe an LdF durch A/W aw0001',
    'Empfänger über ' . estab_message_medium_text('Me')
        . ' (Me) nicht erreichbar. Beförderungsweg: Melder zum '
        . 'Bereitstellungsraum Süd. Grund: Melder nicht verfügbar',
    '01.08.2026 12:00'
);
$assert(
    str_starts_with($mergedNote, $existingNote)
        && str_contains($mergedNote, 'Melder')
        && str_contains($mergedNote, 'nicht erreichbar')
        && str_contains($mergedNote, 'Rückgabe an LdF durch A/W aw0001'),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Der Rückgabevermerk verdrängt die vorhandenen Vermerke oder nennt '
            . 'das TK-Mittel nicht namentlich'
    )
);

// Der Anwendungscode allein genügt nicht: drei Datenbank-Trigger aus den
// Migrationen 94 und 119 vergleichen den Weg aus dem Fernmeldeplan und den
// Melderauftrag gegen Feld 7. Solange sie das tun, scheitert jede
// plangestützte Disposition mit SQLSTATE 45000, sobald Wunsch und
// disponiertes Mittel auseinandergehen -- und dieser Test meldete eine Regel
// als erfüllt, die im Betrieb nicht greift.
$migration = file_get_contents(
    $root . '/docker/db/migrations/121-transport-disposition-field-one.sql'
);
$assert(
    is_string($migration) && $migration !== '',
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Die Migration, die die Grenztrigger auf Feld 1 umstellt, fehlt'
    )
);
$migration = (string) $migration;
foreach ([
    'estab_dv94_message_route_insert',
    'estab_dv94_message_route_update',
    'estab_dv94_messenger_insert',
] as $trigger) {
    $assert(
        str_contains(
            $migration,
            'CREATE OR REPLACE TRIGGER `' . $trigger . '`'
        ),
        estab_dv_requirement(
            'FUEST-KLEIN-BEFOERDERUNG',
            'Der Grenztrigger ' . $trigger . ' vergleicht weiterhin gegen '
                . 'Feld 7 und weist jede Disposition ab, bei der Wunsch und '
                . 'disponiertes Mittel auseinandergehen'
        )
    );
}
$triggerBodies = substr(
    $migration,
    (int) strpos($migration, 'CREATE OR REPLACE TRIGGER')
);
$assert(
    !str_contains($triggerBodies, 'NEW.`06_befwegausw`')
        && !str_contains($triggerBodies, 'message_row.`06_befwegausw`')
        && substr_count($triggerBodies, '`01_medium`') >= 4,
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Ein neu angelegter Grenztrigger vergleicht noch immer gegen Feld 7'
    )
);
$assert(
    str_contains($migration, "'120-single-function-relief.sql'")
        && str_contains($migration, 'predecessor ledger is missing')
        && str_contains($migration, 'trigger collision')
        && str_contains($migration, 'trigger mismatch')
        && str_contains($migration, 'messenger guard lost'),
    estab_dv_requirement(
        'FUEST-KLEIN-BEFOERDERUNG',
        'Die Migration prüft weder ihren Vorgänger noch ihr Ergebnis und '
            . 'kann den Melderschutz stillschweigend verlieren'
    )
);
foreach ([
    'docker/db/verify.sql',
    'app/readiness.php',
    'tests/integration/schema_migrator.sh',
] as $registry) {
    $source = file_get_contents($root . '/' . $registry);
    $assert(
        is_string($source) && str_contains(
            (string) $source,
            '121-transport-disposition-field-one.sql'
        ),
        estab_dv_requirement(
            'FUEST-KLEIN-BEFOERDERUNG',
            'Die Migration ist in ' . $registry . ' nicht registriert und '
                . 'würde im Betrieb nicht angewandt'
        )
    );
}

printf(
    "Small command post transport: OK (%d assertions)\n",
    $assertions
);
