<?php

declare(strict_types=1);

/**
 * DV 1-101, Besetzung der Führungsstelle.
 *
 * A command post without a staff has to remain able to work: the regulation
 * does not demand a complete roster, it demands that the stations of the
 * message run which nobody can serve are named before the incident is
 * released. Naming them is therefore a report and never a gate. This test
 * proves that the stations and their roles cannot drift away from the
 * authoritative assignment policy, that an unanswered station is named
 * instead of silently assumed, that both permission modes ask their own
 * authoritative source, and that the report neither disables a control nor
 * takes the incident administration down when it cannot be produced.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/dv_operations.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $relative) use ($root, $assert): string {
    $source = file_get_contents($root . '/' . $relative);
    $assert(
        is_string($source) && $source !== '',
        estab_dv_requirement(
            'FUEST-BESETZUNG-VOLLSTAENDIG',
            'Die Quelle ' . $relative . ' ist nicht lesbar.'
        )
    );

    return (string) $source;
};
$slice = static function (string $source, string $start, string $end) use (
    $assert
): string {
    $startOffset = strpos($source, $start);
    $endOffset = is_int($startOffset)
        ? strpos($source, $end, $startOffset + strlen($start))
        : false;
    $assert(
        is_int($startOffset) && is_int($endOffset),
        estab_dv_requirement(
            'FUEST-BESETZUNG-VOLLSTAENDIG',
            'Der Quelltextabschnitt "' . $start . '" fehlt.'
        )
    );

    return substr(
        $source,
        (int) $startOffset,
        (int) $endOffset - (int) $startOffset
    );
};

// 1. The stations of the run keep the roles the assignment policy binds.
$cells = array_fill(0, 20, ['function' => '', 'role' => '']);
$cells[0] = [
    'function' => 'S2',
    'role' => 'Stab',
    'redcopy' => true,
    'auto' => false,
];
$policyRoles = estab_assignment_roles_from_matrix(['cells' => $cells]);
$assert(
    array_keys(ESTAB_DV_MESSAGE_RUN_STATIONS) === ['Si', 'S2', 'A/W', 'LdF'],
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Der Nachrichtenlauf benennt nicht genau die Stationen Sichtung, '
        . 'Einsatztagebuch, Annahme/Weitergabe und Fernmeldebetrieb.'
    )
);
foreach (ESTAB_DV_MESSAGE_RUN_STATIONS as $function => $definition) {
    $assert(
        isset($policyRoles[$function])
            && $policyRoles[$function] === $definition['rolle']
            && trim((string) $definition['station']) !== '',
        estab_dv_requirement(
            'FUEST-BESETZUNG-VOLLSTAENDIG',
            'Die Station ' . $function . ' trägt eine Rolle oder Bezeichnung, '
            . 'die nicht zur freigegebenen Funktionszuordnung passt.'
        )
    );
}

// 2. Every station is named, and a missing answer counts as unstaffed.
$unanswered = estab_dv_message_run_report(ESTAB_PERMISSION_MODE_LOOSE, []);
$assert(
    count($unanswered['stationen']) === 4
        && $unanswered['modus'] === ESTAB_PERMISSION_MODE_LOOSE
        && $unanswered['unbesetzt'] === [
            'Sichtung eingehender Nachrichten (Si)',
            'Einsatztagebuch (S2)',
            'Annahme und Weitergabe (Fernmelder)',
            'Leitung des Fernmeldebetriebs (LdF)',
        ],
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Eine Station ohne Antwort gilt als besetzt oder wird nicht in '
        . 'lesbarer Form benannt.'
    )
);
$partial = estab_dv_message_run_report(
    ESTAB_PERMISSION_MODE_STRICT,
    ['Si' => true, 'S2' => true, 'A/W' => false, 'LdF' => 1]
);
$assert(
    $partial['modus'] === ESTAB_PERMISSION_MODE_STRICT
        && $partial['unbesetzt'] === [
            'Annahme und Weitergabe (Fernmelder)',
            'Leitung des Fernmeldebetriebs (LdF)',
        ],
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Eine nur ungefähr wahre Antwort besetzt eine Station des '
        . 'Nachrichtenlaufs.'
    )
);
$complete = estab_dv_message_run_report(
    ESTAB_PERMISSION_MODE_LOOSE,
    ['Si' => true, 'S2' => true, 'A/W' => true, 'LdF' => true]
);
$staffedFlags = array_column($complete['stationen'], 'besetzt');
$assert(
    $complete['unbesetzt'] === []
        && $staffedFlags === [true, true, true, true],
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Eine vollständig besetzte Führungsstelle wird trotzdem bemängelt.'
    )
);
$invalidModeRejected = false;
try {
    estab_dv_message_run_report('IRGENDWAS', []);
} catch (InvalidArgumentException) {
    $invalidModeRejected = true;
}
$assert(
    $invalidModeRejected,
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Der Bericht nimmt einen erfundenen Berechtigungsmodus an.'
    )
);

// 3. Each mode asks its own authoritative source for an unblocked bearer.
$dvSource = $read('app/dv_operations.php');
$staffingSource = $slice(
    $dvSource,
    'function estab_dv_message_run_staffing(',
    'function estab_dv_activate_initial_shift('
);
$strictQuery = $slice(
    $staffingSource,
    '? $connection->prepare(',
    ': $connection->prepare('
);
$looseQuery = $slice(
    $staffingSource,
    ': $connection->prepare(',
    'if (!$statement)'
);
$assert(
    str_contains($staffingSource, 'estab_incident_duty_shift_required($incident)')
        && str_contains($staffingSource, 'return estab_dv_message_run_report('),
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Der Berichtsmodus stammt nicht aus dem Einsatz selbst.'
    )
);
$assert(
    str_contains($strictQuery, '`nv_dienstbesetzungen`')
        && str_contains($strictQuery, "duty_shift.`status` = 'AKTIV'")
        && str_contains($strictQuery, "assignment.`status` = 'ANGENOMMEN'")
        && str_contains($strictQuery, 'BINARY assignment.`funktion` = BINARY ?')
        && str_contains($strictQuery, 'BINARY assignment.`rolle` = BINARY ?')
        && str_contains($strictQuery, 'account.`estab_gesperrt` = 0')
        && str_contains($strictQuery, 'LIMIT 1')
        && !str_contains($strictQuery, '`nv_benutzer_zusatzfunktionen`'),
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Im strengen Modus wird die Besetzung nicht aus der aktiven '
        . 'Dienstbesetzung eines nicht gesperrten Kontos ermittelt.'
    )
);
$assert(
    str_contains($looseQuery, '`nv_benutzer_zusatzfunktionen`')
        && str_contains($looseQuery, 'BINARY account.`funktion` = BINARY ?')
        && str_contains($looseQuery, 'BINARY account.`rolle` = BINARY ?')
        && str_contains($looseQuery, 'BINARY extra.`funktion` = BINARY ?')
        && str_contains($looseQuery, 'account.`estab_gesperrt` = 0')
        && str_contains($looseQuery, 'LIMIT 1')
        && !str_contains($looseQuery, '`nv_dienstbesetzungen`'),
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Im lockeren Modus wird die Besetzung nicht aus Kontofunktion und '
        . 'ausdrücklich vergebener Zusatzfunktion ermittelt.'
    )
);

// 4. Naming the gaps never becomes a gate.
$incidentSource = $read('app/incident.php');
$page = $read('4fadm/incidents.php');
$notice = $slice(
    $page,
    'function incident_admin_staffing_notice(',
    'function incident_admin_redirect('
);
$assert(
    !str_contains($incidentSource, 'estab_dv_message_run_staffing')
        && !str_contains($staffingSource, 'EstabDvPermissionException')
        && !str_contains($staffingSource, 'EstabDvConflictException'),
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Die Besetzungsprüfung greift in die Freigabe des Einsatzes ein.'
    )
);
$assert(
    !str_contains($notice, 'disabled')
        && !str_contains($notice, '<form')
        && !str_contains($notice, '<button')
        && str_contains($notice, 'incident_admin_html($label)')
        && str_contains($page, "estab_logbook_lifecycle_missing_header(\$incident) !== []")
        && substr_count($page, "'disabled' : ''") === 2,
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Die Meldung sperrt die Aktivierung oder gibt Stationsnamen '
        . 'unmaskiert aus.'
    )
);

// 4b. A report that cannot be produced must not take the page down.
$collection = $slice(
    $page,
    'foreach ($incidents as $listedIncident) {',
    '} finally {'
);
$assert(
    str_contains($collection, 'try {')
        && str_contains($collection, 'catch (Throwable $staffingException)')
        && str_contains($collection, 'error_log(')
        && str_contains($collection, 'break;'),
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Ein nicht erstellbarer Besetzungsbericht reißt die '
        . 'Einsatzverwaltung mit und sperrt damit die Freigabe des Einsatzes.'
    )
);

// 5. The administration names the gaps before and after the release.
$assert(
    str_contains($page, "require_once __DIR__ . '/../app/dv_operations.php';")
        && substr_count($page, 'estab_dv_message_run_staffing(') === 1
        && substr_count($page, '$messageRunStaffing[') === 3
        && substr_count($page, 'incident_admin_staffing_notice(') === 3
        && str_contains($page, '$messageRunStaffing[(int) $activeId] ?? null')
        && str_contains($page, "(\$listedIncident['estab_status'] ?? null) === 'closed'"),
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Die Einsatzverwaltung benennt die unbesetzten Stationen nicht am '
        . 'aktiven Einsatz und am Aktivierungsformular.'
    )
);
$assert(
    str_contains($notice, 'data-estab-message-run-staffing="complete"')
        && str_contains($notice, "'planned'")
        && str_contains($notice, "'incomplete'")
        && str_contains($notice, 'estab-tool-notice-warning')
        && str_contains($notice, 'count($missing) === count($stations)'),
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Die Meldung unterscheidet nicht zwischen vollständiger Besetzung, '
        . 'noch zu planender Dienstschicht und tatsächlicher Lücke, oder sie '
        . 'nimmt die Planungslage an, statt sie aus dem Bericht abzuleiten.'
    )
);

// 6. The sidebar keeps its own, cheaper presence view instead of a copy.
$sidebar = $read('app/sidebar.php');
$assert(
    !str_contains($sidebar, 'estab_dv_message_run_staffing')
        && str_contains($sidebar, 'Aktivität nach Primärfunktion'),
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Die Seitenleiste wiederholt den Besetzungsbericht bei jedem '
        . 'Statusabruf, statt ihre Anwesenheitsanzeige zu behalten.'
    )
);

echo "Command post staffing: OK ({$assertions} assertions)\n";
