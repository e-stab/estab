<?php

declare(strict_types=1);

/**
 * Publish disposable HTTP-test telecommunications plans through production
 * domain functions. This fixture runs only in the explicitly LOOSE central
 * incident. Direct SQL would bypass the fixed S6 capability and the exact
 * immutable draft/release transitions proved by migration 94.
 */

require_once dirname(__DIR__, 2) . '/app/dv_operations.php';

function http_telecom_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException(
            "Missing HTTP telecommunications fixture value: {$name}"
        );
    }
    return $value;
}

if (getenv('ESTAB_TEST_TELECOM_ALLOW_MUTATION') !== 'true') {
    throw new RuntimeException(
        'HTTP telecommunications fixture mutation flag is required'
    );
}
$project = http_telecom_env('COMPOSE_PROJECT_NAME');
if (
    $project !== 'estab_ci'
    && !str_starts_with($project, 'estab_ci_')
    && $project !== 'estab-integrity-http'
    && !str_starts_with($project, 'estab-integrity-http-')
) {
    throw new RuntimeException(
        'Refusing HTTP telecommunications fixture outside a disposable project'
    );
}
$mode = http_telecom_env('ESTAB_TEST_TELECOM_MODE');
if (!in_array($mode, ['initial', 'successor'], true)) {
    throw new RuntimeException('Unknown HTTP telecommunications fixture mode');
}
$token = http_telecom_env('ESTAB_TEST_TELECOM_TOKEN');
if (preg_match('/\A[a-zA-Z0-9_-]{1,48}\z/D', $token) !== 1) {
    throw new RuntimeException('Invalid HTTP telecommunications fixture token');
}
$s6Code = http_telecom_env('ESTAB_TEST_TELECOM_S6_CODE');
$databasePassword = getenv('ESTAB_DB_PASSWORD');
if (!is_string($databasePassword) || $databasePassword === '') {
    $passwordFile = getenv('ESTAB_DB_PASSWORD_FILE');
    $databasePassword =
        is_string($passwordFile) && is_readable($passwordFile)
            ? trim((string) file_get_contents($passwordFile))
            : '';
}
if ($databasePassword === '') {
    throw new RuntimeException(
        'HTTP telecommunications fixture database password is required'
    );
}
$config = [
    'server' => http_telecom_env('ESTAB_DB_HOST')
        . ':' . http_telecom_env('ESTAB_DB_PORT'),
    'user' => http_telecom_env('ESTAB_DB_USER'),
    'password' => $databasePassword,
    'datenbank' => http_telecom_env('ESTAB_DB_NAME'),
];

$connection = estab_auth_connect($config);
$fixtureSessionId = null;
try {
    $incident = estab_incident_status($connection);
    $incidentId = $incident['active_einsatz_id'] ?? null;
    if (!is_int($incidentId) || $incidentId < 1) {
        throw new RuntimeException(
            'HTTP telecommunications fixture requires an active incident'
        );
    }
    if (($incident['estab_permission_mode'] ?? null) !== 'LOOSE') {
        throw new RuntimeException(
            'HTTP telecommunications fixture requires the explicit LOOSE mode'
        );
    }
    $accountStatement = $connection->prepare(
        'SELECT account.`benutzer`, account.`kuerzel`, account.`funktion`,'
        . ' account.`rolle`, account.`sid`, account.`ip`, account.`fwdip`,'
        . ' account.`aktiv`, account.`estab_gesperrt`, account.`password`'
        . ' FROM `nv_benutzer` AS account'
        . ' WHERE BINARY account.`kuerzel` = BINARY ?'
        . " AND BINARY account.`funktion` = BINARY 'S6'"
        . " AND BINARY account.`rolle` = BINARY 'Stab'"
        . ' AND account.`estab_gesperrt` = 0'
        . ' LIMIT 1'
    );
    if (!$accountStatement) {
        throw new RuntimeException(
            'HTTP telecommunications S6 account could not be prepared'
        );
    }
    try {
        $accountStatement->bind_param('s', $s6Code);
        $accountStatement->execute();
        $account = $accountStatement->get_result()->fetch_assoc();
    } finally {
        $accountStatement->close();
    }
    if (!is_array($account)) {
        throw new RuntimeException(
            'HTTP telecommunications fixture requires an unblocked S6 account'
        );
    }
    $accountActive = (int) ($account['aktiv'] ?? 0);
    $accountSessionId = is_string($account['sid'] ?? null)
        ? (string) $account['sid']
        : '';
    $hasActiveSession = $accountActive === 1
        && estab_auth_session_id_is_valid($accountSessionId);
    $isSignedOut = $accountActive === 0 && $accountSessionId === '';
    if (!$hasActiveSession && !$isSignedOut) {
        throw new RuntimeException(
            'HTTP telecommunications fixture found an inconsistent S6 session'
        );
    }
    if ($isSignedOut) {
        // Administrative provisioning deliberately creates an inactive
        // account. Give only this guarded, disposable fixture a bounded
        // synthetic session so the production domain API still proves its
        // normal active-account boundary. A genuine existing session is never
        // replaced. The exact synthetic SID is revoked in finally before the
        // browser logs in itself.
        $syntheticSessionId = 'fixture-' . bin2hex(random_bytes(16));
        estab_auth_update_user(
            $connection,
            'nv_benutzer',
            [
                'benutzer' => (string) $account['benutzer'],
                'kuerzel' => (string) $account['kuerzel'],
                'funktion' => (string) $account['funktion'],
                'rolle' => (string) $account['rolle'],
                'sid' => $syntheticSessionId,
                'ip' => '127.0.0.1',
                'fwdip' => '',
                'password' => (string) $account['password'],
            ]
        );
        $fixtureSessionId = $syntheticSessionId;
    }
    $identity = [
        'benutzer' => (string) $account['benutzer'],
        'kuerzel' => $s6Code,
        'funktion' => 'S6',
        'rolle' => 'Stab',
    ];
    $validFrom = date('Y-m-d H:i:s', time() - 86400);
    $validUntil = date('Y-m-d H:i:s', time() + 86400);

    $publish = static function (
        string $origin,
        string $station,
        string $callSign,
        // Die Wegart, nicht mehr das blosse Mittel: "Fu" allein gibt es seit
        // der Trennung von Analog- und Digitalfunk nicht mehr.
        string $routeKind,
        string $channel,
        string $band,
        string $traffic,
        string $specialNote,
        string $note
    ) use (
        $connection,
        $incidentId,
        $identity,
        $validFrom,
        $validUntil
    ): array {
        $activePlan = null;
        foreach (estab_dv_telecom_plans($connection, $incidentId) as $candidate) {
            if ($candidate['status'] === 'AKTIV') {
                $activePlan = $candidate;
                break;
            }
        }
        $header = [
            'herkunft' => $origin,
            'gueltig_ab' => $validFrom,
            'gueltig_bis' => $validUntil,
            'betriebsleitung' => 'S6 CI',
            'bemerkungen' => $note,
        ];
        if (is_array($activePlan)) {
            $plan = estab_dv_start_telecom_plan_revision(
                $connection,
                $incidentId,
                (int) $activePlan['fernmeldeplan_id'],
                $identity
            );
            $draft = null;
            foreach (
                estab_dv_telecom_plans($connection, $incidentId) as $candidate
            ) {
                if (
                    (int) $candidate['fernmeldeplan_id']
                        === (int) $plan['fernmeldeplan_id']
                ) {
                    $draft = $candidate;
                    break;
                }
            }
            if (!is_array($draft)) {
                throw new RuntimeException('Cloned plan draft was not readable');
            }
            estab_dv_update_telecom_plan_draft(
                $connection,
                $incidentId,
                (int) $plan['fernmeldeplan_id'],
                $identity,
                $header,
                (string) $draft['revision']
            );
        } else {
            $plan = estab_dv_create_telecom_plan(
                $connection,
                $incidentId,
                $identity,
                $header
            );
        }
        $planId = (int) $plan['fernmeldeplan_id'];
        $draft = null;
        foreach (estab_dv_telecom_plans($connection, $incidentId) as $candidate) {
            if ((int) $candidate['fernmeldeplan_id'] === $planId) {
                $draft = $candidate;
                break;
            }
        }
        if (!is_array($draft)) {
            throw new RuntimeException('Plan draft was not readable');
        }
        $routeId = estab_dv_add_telecom_entry(
            $connection,
            $incidentId,
            $planId,
            $identity,
            [
                'betriebsstelle' => $station,
                // Migration 124: aus `rufname` wurde `erreichbarkeit`.
                'erreichbarkeit' => $callSign,
                'wegart' => $routeKind,
                // Analogfunk verlangt ein Band; jede andere Wegart besitzt
                // das Feld nicht und laesst den Wert fallen.
                'band' => $routeKind === 'Fu:ANALOG' ? '2m' : '',
                'kanal' => $channel,
                'bandlage' => $band,
                'verkehrsform' => $traffic,
                'besondere_vermerke' => $specialNote,
                'bemerkungen' => $note,
            ],
            'nv_protokoll',
            (string) $draft['revision']
        );
        $draft = null;
        foreach (estab_dv_telecom_plans($connection, $incidentId) as $candidate) {
            if ((int) $candidate['fernmeldeplan_id'] === $planId) {
                $draft = $candidate;
                break;
            }
        }
        if (!is_array($draft)) {
            throw new RuntimeException('Updated plan draft was not readable');
        }
        estab_dv_activate_telecom_plan(
            $connection,
            $incidentId,
            $planId,
            $identity,
            'nv_protokoll',
            (string) $draft['revision']
        );
        return [
            'route_id' => $routeId,
            'version' => (int) $plan['version'],
        ];
    };

    if ($mode === 'initial') {
        $replaced = $publish(
            'CI-ERSETZT-' . $token,
            'Ersetzte Betriebsstelle',
            'Alt-Rufname',
            'Me',
            'ALT',
            'alt',
            'alt',
            'Ersetzter besonderer Vermerk',
            'Ersetzter Manipulations-Fixpunkt'
        );
        $active = $publish(
            'CI-AKTIV-' . $token,
            'CI Betriebsstelle',
            'CI Rufname',
            'Fu:ANALOG',
            'Kanal 404',
            'G/U',
            'Gegenverkehr',
            'Aktiver besonderer Vermerk',
            'Aktiver Workflow-Fixpunkt'
        );
        printf(
            "%d|%d|%d\n",
            $replaced['route_id'],
            $active['route_id'],
            $active['version']
        );
    } else {
        $active = $publish(
            'CI-AKTIV-B-' . $token,
            'CI Ersatz-Betriebsstelle',
            'CI Ersatz-Rufname',
            'Fu:ANALOG',
            'Kanal 505',
            'O/U',
            'Wechselverkehr',
            'Redisposition besonderer Vermerk',
            'Redisposition Route B'
        );
        printf("%d|%d\n", $active['route_id'], $active['version']);
    }
} finally {
    try {
        if (
            is_string($fixtureSessionId)
            && !estab_auth_mark_logged_out(
                $connection,
                'nv_benutzer',
                $s6Code,
                $fixtureSessionId
            )
        ) {
            throw new RuntimeException(
                'HTTP telecommunications fixture session cleanup failed'
            );
        }
    } finally {
        estab_auth_close($connection);
    }
}
