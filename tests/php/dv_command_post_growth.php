<?php

declare(strict_types=1);

/**
 * Die Führungsstelle wächst im laufenden Einsatz auf.
 *
 * DV 1-101 kennt keine Führungsstelle, die ihre Führungsstufe bei
 * Einsatzbeginn endgültig festlegt: aus der Führungsstelle ohne Stab wird bei
 * steigender Lage die Führungsstelle mit Stab. Der Berechtigungsmodus muss
 * diesem Aufwuchs folgen, sonst zwingt die Anwendung eine wachsende
 * Führungsstelle in einen neuen Einsatz und zerschneidet damit den Nachweis.
 * Die Gegenrichtung bleibt gesperrt: eine bereits formal geführte
 * Führungsstelle darf ihre Nachweisführung nicht abschwächen. Dieser Test
 * hält fest, dass der Aufwuchs möglich, ausdrücklich bestätigt, im
 * Einsatztagebuch nachgewiesen und erst mit einer vollständig besetzten
 * Dienstschicht wieder eingabefähig ist. Er hält ausserdem fest, dass die
 * Schichtaktivierung die bereits eröffneten Bücher nur dann stehen lässt,
 * wenn der Aufwuchs im Einsatzprotokoll nachgewiesen ist.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/permission_mode.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $source;
};
$slice = static function (
    string $source,
    string $startMarker,
    string $endMarker
): string {
    $start = strpos($source, $startMarker);
    $end = strpos(
        $source,
        $endMarker,
        $start === false ? 0 : $start + strlen($startMarker)
    );
    if (!is_int($start) || !is_int($end) || $end <= $start) {
        throw new RuntimeException(
            'Could not isolate the region starting at ' . $startMarker
        );
    }
    return substr($source, $start, $end - $start);
};
$before = static function (string $haystack, string $first, string $second): bool {
    $firstOffset = strpos($haystack, $first);
    $secondOffset = strpos($haystack, $second);
    return is_int($firstOffset)
        && is_int($secondOffset)
        && $firstOffset < $secondOffset;
};

$looseIncident = [
    'active_einsatz_id' => 42,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_LOOSE,
    'revision' => 17,
];
$grownIncident = [
    'active_einsatz_id' => 42,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
    'revision' => 18,
];
estab_permission_context_set_from_incident($looseIncident);
$assert(
    estab_permission_duty_shift_required() === false
        && estab_permission_loose_mode_active(),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Die Führungsstelle ohne Stab verlangt bereits eine Dienstschicht'
    )
);
$looseSnapshot = estab_permission_context();
estab_permission_context_set_from_incident($grownIncident);
$assert(
    estab_permission_duty_shift_required() === true
        && !estab_permission_loose_mode_active(),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Nach dem Aufwuchs verlangt die Führungsstelle keine Dienstschicht'
    )
);

$assert(
    is_array($looseSnapshot)
        && !estab_permission_context_snapshot_matches_incident(
            $looseSnapshot,
            $grownIncident
        )
        && estab_permission_context_snapshot_matches_incident(
            $looseSnapshot,
            $looseIncident
        ),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Ein vor dem Aufwuchs begonnener Vorgang schreibt mit dem alten '
        . 'Berechtigungsstand weiter'
    )
);
$permissionContextKey = ESTAB_PERMISSION_CONTEXT_KEY;
unset($GLOBALS[$permissionContextKey]);
$assert(
    estab_permission_duty_shift_required(),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Ohne Kontext fällt die Anwendung nicht auf die Führungsstelle mit '
        . 'Stab zurück'
    )
);

$modeUpdate = $slice(
    $read('app/incident.php'),
    'function estab_incident_update_permission_mode(',
    'function estab_incident_activate_locked('
);
$assert(
    str_contains(
        $modeUpdate,
        '$growth = $currentMode === ESTAB_PERMISSION_MODE_LOOSE'
    )
        && str_contains(
            $modeUpdate,
            '&& $mode === ESTAB_PERMISSION_MODE_STRICT;'
        )
        && str_contains(
            $modeUpdate,
            "if (\$hasOperationalData && !\$growth) {\n"
            . '            throw new EstabIncidentConflictException('
        ),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Der Moduswechsel unterscheidet Aufwuchs und Abschwächung nicht'
    )
);
$assert(
    str_contains($modeUpdate, 'bool $confirmedGrowth = false')
        && str_contains($modeUpdate, '!$confirmedGrowth')
        && str_contains($modeUpdate, 'if ($hasOperationalData && !$activeTarget) {'),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Der Aufwuchs im laufenden Einsatz ist weder ausdrücklich bestätigt '
        . 'noch auf den aktiven Einsatz begrenzt'
    )
);

$assert(
    str_contains($modeUpdate, 'estab_logbook_lifecycle_insert_etb(')
        && $before(
            $modeUpdate,
            'estab_logbook_lifecycle_insert_etb(',
            'SET @estab_permission_mode_admin_write_id'
        )
        && $before(
            $modeUpdate,
            'estab_incident_has_operational_data($connection, $incidentId)',
            'estab_logbook_lifecycle_insert_etb('
        )
        && str_contains($modeUpdate, "'berechtigung_geaendert'")
        && str_contains($modeUpdate, '$connection->rollback()'),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Der Aufwuchs wird nicht vor der Umschaltung im Einsatztagebuch '
        . 'nachgewiesen'
    )
);

$incidentDomain = $read('app/incident.php');
$assert(
    str_contains($incidentDomain, 'function estab_incident_grew_to_strict(')
        && str_contains($incidentDomain, '`nv_einsatz_ereignisse`')
        && str_contains($incidentDomain, "'berechtigung_geaendert'"),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Der Aufwuchs laesst sich spaeter nicht mehr aus dem '
        . 'Einsatzprotokoll nachweisen'
    )
);

$operationsDomain = $read('app/dv_operations.php');
$assert(
    str_contains(
        $operationsDomain,
        "const ESTAB_DV_REQUIRED_HATS = ['S2', 'Si', 'S6', 'LdF', 'A/W'];"
    ),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Die Pflichtfunktionen der Führungsstelle mit Stab sind nicht mehr '
        . 'vollständig hinterlegt'
    )
);
$shiftActivation = $slice(
    $operationsDomain,
    'function estab_dv_activate_initial_shift(',
    'function estab_dv_initiate_handover_shift('
);
$assert(
    str_contains(
        $shiftActivation,
        'estab_dv_shift_required_hats($connection, $shiftId)'
    )
        && str_contains($shiftActivation, 'estab_incident_duty_shift_required($incident)')
        && $before(
            $shiftActivation,
            'estab_dv_shift_required_hats($connection, $shiftId)',
            'estab_incident_grew_to_strict($connection, $incidentId)'
        )
        && $before(
            $shiftActivation,
            'estab_incident_grew_to_strict($connection, $incidentId)',
            'estab_logbook_lifecycle_open_books_if_empty('
        )
        && str_contains(
            $shiftActivation,
            'estab_logbook_lifecycle_open_books_if_empty('
        )
        && str_contains($shiftActivation, 'estab_logbook_lifecycle_insert_etb('),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Die erste Dienstschicht nach dem Aufwuchs scheitert an den bereits '
        . 'eröffneten Büchern oder umgeht die Pflichtfunktionen'
    )
);
$assert(
    str_contains(
        $shiftActivation,
        'if (!estab_incident_grew_to_strict($connection, $incidentId)) {'
    )
        && $before(
            $shiftActivation,
            'estab_logbook_lifecycle_open_books(',
            'estab_logbook_lifecycle_open_books_if_empty('
        ),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Ohne nachgewiesenen Aufwuchs übergeht die Schichtaktivierung den '
        . 'Schutz eines unerklärten Logbuchbestands'
    )
);

$operationalAccount = $slice(
    $operationsDomain,
    'function estab_dv_require_operational_account(',
    'function estab_dv_effective_identity_capability_function('
);
$assert(
    $before(
        $operationalAccount,
        "'estab_permission_mode' => 'STRICT',",
        "\$shape['estab_additional_functions'] ="
    )
        && $before(
            $operationalAccount,
            'estab_incident_duty_shift_required($incident)',
            "\$shape['estab_additional_functions'] ="
        ),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Zusatzfunktionen aus dem lockeren Betrieb tragen nach dem Aufwuchs '
        . 'weiter operative Eingaben'
    )
);
$capabilityScope = $slice(
    $read('app/read_authorization.php'),
    'function estab_read_effective_capability_scope(',
    "'params' => ["
);
$assert(
    $before(
        $capabilityScope,
        "= BINARY 'LOOSE'",
        '`nv_benutzer_zusatzfunktionen` AS extra'
    ),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Eine Zusatzfunktion öffnet den Lesezugriff auch nach dem Aufwuchs'
    )
);

$adminSource = $read('4fadm/incidents.php');
$assert(
    str_contains($adminSource, 'name="confirm_permission_growth"')
        && str_contains(
            $adminSource,
            "(\$_POST['confirm_permission_growth'] ?? null) === '1'"
        ),
    estab_dv_requirement(
        'FUEST-AUFWUCHS',
        'Die Einsatzverwaltung bietet den bestätigten Aufwuchs nicht an'
    )
);

echo 'DV command post growth: OK (' . $assertions . " assertions)\n";
