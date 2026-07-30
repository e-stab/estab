<?php

declare(strict_types=1);

/**
 * Build the active duty-shift fixture for the disposable HTTP workflow test.
 *
 * Every state change intentionally crosses the production DV domain boundary:
 * create the planned shift, assign each function hat, let the assigned account
 * accept it personally, and only then activate the complete shift.
 */

require_once dirname(__DIR__, 2) . '/app/dv_operations.php';

function http_shift_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("Missing HTTP shift fixture value: {$name}");
    }
    return $value;
}

function http_shift_optional_env(string $name): ?string
{
    $value = getenv($name);
    return is_string($value) && $value !== '' ? $value : null;
}

if (getenv('ESTAB_TEST_SHIFT_ALLOW_MUTATION') !== 'true') {
    throw new RuntimeException('HTTP shift fixture mutation flag is required');
}
$project = http_shift_env('COMPOSE_PROJECT_NAME');
if (
    $project !== 'estab_ci'
    && !str_starts_with($project, 'estab_ci_')
    && $project !== 'estab-integrity-http'
    && !str_starts_with($project, 'estab-integrity-http-')
) {
    throw new RuntimeException(
        'Refusing HTTP shift fixture outside a recognized disposable project'
    );
}

$config = [
    'server' => http_shift_env('ESTAB_DB_HOST')
        . ':' . http_shift_env('ESTAB_DB_PORT'),
    'user' => http_shift_env('ESTAB_DB_USER'),
    'password' => http_shift_env('ESTAB_DB_PASSWORD'),
    'datenbank' => http_shift_env('ESTAB_DB_NAME'),
];
$functionCodes = [
    'A/W' => http_shift_env('ESTAB_TEST_SHIFT_AW_CODE'),
    'LdF' => http_shift_env('ESTAB_TEST_SHIFT_LDF_CODE'),
    'Si' => http_shift_env('ESTAB_TEST_SHIFT_SI_CODE'),
    'S2' => http_shift_env('ESTAB_TEST_SHIFT_S2_CODE'),
    'S6' => http_shift_env('ESTAB_TEST_SHIFT_S6_CODE'),
];
foreach ([
    'S1' => 'ESTAB_TEST_SHIFT_S1_CODE',
    'S3' => 'ESTAB_TEST_SHIFT_S3_CODE',
    'POL' => 'ESTAB_TEST_SHIFT_POL_CODE',
] as $function => $environmentName) {
    $userCode = http_shift_optional_env($environmentName);
    if ($userCode !== null) {
        $functionCodes[$function] = $userCode;
    }
}
$actor = 'ci-http-shift-fixture';

$connection = estab_auth_connect($config);
try {
    $incident = estab_incident_status($connection);
    $incidentId = $incident['active_einsatz_id'] ?? null;
    if (!is_int($incidentId) || $incidentId < 1) {
        throw new RuntimeException('HTTP workflow fixture requires an active incident');
    }

    $activeStatement = $connection->prepare(
        'SELECT `dienstschicht_id` FROM `nv_dienstschichten`'
        . " WHERE `einsatz_id` = ? AND `status` = 'AKTIV'"
        . ' ORDER BY `dienstschicht_id` DESC LIMIT 1'
    );
    if (!$activeStatement) {
        throw new RuntimeException(
            'HTTP workflow fixture could not inspect the active duty shift'
        );
    }
    try {
        $activeStatement->bind_param('i', $incidentId);
        $activeStatement->execute();
        $activeRow = $activeStatement->get_result()->fetch_assoc();
    } finally {
        $activeStatement->close();
    }
    $predecessorId = is_array($activeRow)
        ? (int) ($activeRow['dienstschicht_id'] ?? 0)
        : 0;

    $created = estab_dv_create_shift(
        $connection,
        $incidentId,
        $predecessorId > 0
            ? 'HTTP Nachrichtenworkflow – Nachfolgedienst'
            : 'HTTP Integrationsdienst',
        $predecessorId > 0 ? $predecessorId : null,
        $actor
    );
    $shiftId = (int) ($created['dienstschicht_id'] ?? 0);
    if ($shiftId < 1) {
        throw new RuntimeException('HTTP workflow fixture did not create a duty shift');
    }

    $assignments = [];
    foreach ($functionCodes as $function => $userCode) {
        $assignment = estab_dv_assign_hat(
            $connection,
            $incidentId,
            $shiftId,
            $userCode,
            $function,
            $actor
        );
        $assignmentId = (int) ($assignment['dienstbesetzung_id'] ?? 0);
        if ($assignmentId < 1) {
            throw new RuntimeException("HTTP workflow fixture did not assign {$function}");
        }
        $assignments[$function] = [
            'dienstbesetzung_id' => $assignmentId,
            'benutzer_kuerzel' => $userCode,
            'funktion' => (string) ($assignment['funktion'] ?? $function),
            'rolle' => (string) ($assignment['rolle'] ?? ''),
        ];
        estab_dv_accept_hat(
            $connection,
            $incidentId,
            $assignmentId,
            $userCode
        );
    }

    if ($predecessorId < 1) {
        estab_dv_activate_initial_shift(
            $connection,
            $incidentId,
            $shiftId,
            $actor
        );
        $transition = 'initial';
    } else {
        $confirming = $assignments['S1'] ?? null;
        if (!is_array($confirming)) {
            throw new RuntimeException(
                'Successor HTTP shift requires an incoming S1 confirmation'
            );
        }
        $confirmingCode = (string) $confirming['benutzer_kuerzel'];
        $accountStatement = $connection->prepare(
            'SELECT `benutzer`, `aktiv`, `estab_gesperrt`'
            . ' FROM `nv_benutzer` WHERE BINARY `kuerzel` = BINARY ?'
        );
        if (!$accountStatement) {
            throw new RuntimeException(
                'HTTP workflow fixture could not inspect its confirming account'
            );
        }
        try {
            $accountStatement->bind_param('s', $confirmingCode);
            $accountStatement->execute();
            $account = $accountStatement->get_result()->fetch_assoc();
        } finally {
            $accountStatement->close();
        }
        if (
            !is_array($account)
            || (int) ($account['aktiv'] ?? 0) !== 1
            || (int) ($account['estab_gesperrt'] ?? 1) !== 0
        ) {
            throw new RuntimeException(
                'Incoming S1 must be signed in and unblocked for personal '
                . 'handover confirmation'
            );
        }
        $requestId = estab_dv_initiate_handover_shift(
            $connection,
            $incidentId,
            $predecessorId,
            $shiftId,
            'Automatisierter, persönlich bestätigter Wechsel des '
                . 'HTTP-Integrationsdienstes.',
            $actor
        );
        estab_dv_confirm_handover_shift(
            $connection,
            $incidentId,
            $requestId,
            (int) $confirming['dienstbesetzung_id'],
            [
                'benutzer' => (string) ($account['benutzer'] ?? ''),
                'kuerzel' => $confirmingCode,
                'funktion' => (string) $confirming['funktion'],
                'rolle' => (string) $confirming['rolle'],
            ]
        );
        $transition = 'handover';
    }
    printf(
        "HTTP workflow duty shift active: %d (%d accepted hats, %s)\n",
        $shiftId,
        count($functionCodes),
        $transition
    );
} finally {
    estab_auth_close($connection);
}
