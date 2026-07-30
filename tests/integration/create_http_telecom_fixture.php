<?php

declare(strict_types=1);

/**
 * Publish disposable HTTP-test telecommunications plans through production
 * domain functions. Direct SQL would bypass the selected S6 capability and
 * the exact immutable draft/release transitions proved by migration 94.
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
try {
    $incident = estab_incident_status($connection);
    $incidentId = $incident['active_einsatz_id'] ?? null;
    if (!is_int($incidentId) || $incidentId < 1) {
        throw new RuntimeException(
            'HTTP telecommunications fixture requires an active incident'
        );
    }
    $assignmentStatement = $connection->prepare(
        'SELECT assignment.`dienstbesetzung_id`, account.`benutzer`'
        . ' FROM `nv_dienstbesetzungen` AS assignment'
        . ' JOIN `nv_dienstschichten` AS shift_row'
        . ' ON shift_row.`dienstschicht_id` ='
        . ' assignment.`dienstschicht_id`'
        . ' JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel` ='
        . ' BINARY assignment.`benutzer_kuerzel`'
        . ' WHERE shift_row.`einsatz_id` = ?'
        . " AND shift_row.`status` = 'AKTIV'"
        . " AND assignment.`status` = 'ANGENOMMEN'"
        . " AND BINARY assignment.`funktion` = BINARY 'S6'"
        . " AND BINARY assignment.`rolle` = BINARY 'Stab'"
        . ' AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
        . ' AND account.`aktiv` = 1'
        . ' AND account.`estab_gesperrt` = 0'
        . ' LIMIT 1'
    );
    if (!$assignmentStatement) {
        throw new RuntimeException(
            'HTTP telecommunications S6 assignment could not be prepared'
        );
    }
    try {
        $assignmentStatement->bind_param('is', $incidentId, $s6Code);
        $assignmentStatement->execute();
        $assignment = $assignmentStatement->get_result()->fetch_assoc();
    } finally {
        $assignmentStatement->close();
    }
    if (!is_array($assignment)) {
        throw new RuntimeException(
            'HTTP telecommunications fixture requires an active accepted S6'
        );
    }
    $identity = [
        'benutzer' => (string) $assignment['benutzer'],
        'kuerzel' => $s6Code,
        'funktion' => 'S6',
        'rolle' => 'Stab',
        'duty_assignment_id' =>
            (int) $assignment['dienstbesetzung_id'],
    ];
    $validFrom = date('Y-m-d H:i:s', time() - 86400);
    $validUntil = date('Y-m-d H:i:s', time() + 86400);

    $publish = static function (
        string $origin,
        string $station,
        string $callSign,
        string $medium,
        string $channel,
        string $band,
        string $traffic,
        string $note
    ) use (
        $connection,
        $incidentId,
        $identity,
        $validFrom,
        $validUntil
    ): array {
        $plan = estab_dv_create_telecom_plan(
            $connection,
            $incidentId,
            $identity,
            [
                'herkunft' => $origin,
                'gueltig_ab' => $validFrom,
                'gueltig_bis' => $validUntil,
                'betriebsleitung' => 'S6 CI',
                'bemerkungen' => $note,
            ]
        );
        $planId = (int) $plan['fernmeldeplan_id'];
        $routeId = estab_dv_add_telecom_entry(
            $connection,
            $incidentId,
            $planId,
            $identity,
            [
                'betriebsstelle' => $station,
                'rufname' => $callSign,
                'medium' => $medium,
                'kanal' => $channel,
                'bandlage' => $band,
                'verkehrsform' => $traffic,
                'besondere_vermerke' => '',
                'bemerkungen' => $note,
            ]
        );
        estab_dv_activate_telecom_plan(
            $connection,
            $incidentId,
            $planId,
            $identity
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
            'Ersetzter Manipulations-Fixpunkt'
        );
        $active = $publish(
            'CI-AKTIV-' . $token,
            'CI Betriebsstelle',
            'CI Rufname',
            'Fu',
            'Kanal 404',
            'G/U',
            'Gegenverkehr',
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
            'Fu',
            'Kanal 505',
            'O/U',
            'Wechselverkehr',
            'Redisposition Route B'
        );
        printf("%d|%d\n", $active['route_id'], $active['version']);
    }
} finally {
    estab_auth_close($connection);
}
