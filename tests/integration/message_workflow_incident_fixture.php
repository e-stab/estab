<?php

declare(strict_types=1);

if (
    PHP_SAPI !== 'cli'
    || getenv('ESTAB_MESSAGE_WORKFLOW_INCIDENT_FIXTURE') !== '1'
    || preg_match(
        '/\Aestab_ci(?:_[a-z0-9_-]+)?\z/D',
        getenv('ESTAB_MESSAGE_WORKFLOW_INCIDENT_PROJECT') ?: ''
    ) !== 1
) {
    fwrite(STDERR, "Refusing message-workflow incident fixture mutation\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/app/incident.php';

$password = getenv('ESTAB_DB_PASSWORD');
if (!is_string($password) || $password === '') {
    $passwordFile = getenv('ESTAB_DB_PASSWORD_FILE');
    $password = is_string($passwordFile) && is_readable($passwordFile)
        ? trim((string) file_get_contents($passwordFile))
        : '';
}
if ($password === '') {
    fwrite(STDERR, "Message-workflow fixture database password is required\n");
    exit(2);
}

$databaseConfig = [
    'server' => getenv('ESTAB_DB_HOST') ?: 'db',
    'user' => getenv('ESTAB_DB_USER') ?: 'estab',
    'password' => $password,
    'datenbank' => getenv('ESTAB_DB_NAME') ?: 'estab',
];
unset($password);

$action = $argv[1] ?? '';
$actor = 'message-workflow-http';

$positiveId = static function (mixed $value): int {
    if (
        !is_string($value)
        || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1
    ) {
        throw new InvalidArgumentException('Invalid fixture incident ID');
    }
    return (int) $value;
};

$activate = static function (
    mysqli $connection,
    int $incidentId,
    string $actor
): array {
    $incident = estab_incident_find($connection, $incidentId);
    if (!is_array($incident) || ($incident['estab_status'] ?? null) !== 'open') {
        throw new RuntimeException('Fixture incident is unavailable');
    }
    $status = estab_incident_status($connection);
    return estab_incident_activate(
        $connection,
        $incidentId,
        (int) $status['revision'],
        $actor,
        ($incident['estab_permission_mode'] ?? null)
            === ESTAB_PERMISSION_MODE_LOOSE
    );
};

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = estab_auth_connect($databaseConfig);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    if ($action === 'create') {
        $mode = $argv[2] ?? '';
        $identifier = $argv[3] ?? '';
        $activateImmediately = $argv[4] ?? '';
        if (
            !in_array(
                $mode,
                [ESTAB_PERMISSION_MODE_STRICT, ESTAB_PERMISSION_MODE_LOOSE],
                true
            )
            || preg_match(
                '/\ACI-MWF-(?:STRICT|LOOSE)-[a-f0-9]{5}\z/D',
                $identifier
            ) !== 1
            || !str_starts_with($identifier, 'CI-MWF-' . $mode . '-')
            || !in_array($activateImmediately, ['0', '1'], true)
        ) {
            throw new InvalidArgumentException(
                'Invalid message-workflow incident fixture request'
            );
        }

        $shouldActivate = $activateImmediately === '1';
        $status = estab_incident_status($connection);
        $created = estab_incident_create(
            $connection,
            [
                'kennung' => $identifier,
                'estab_permission_mode' => $mode,
                'name' => 'HTTP-Nachrichtenworkflow ' . $mode,
                'beginn' => date('Y-m-d\TH:i'),
                'ort' => 'Integrationsprüfung',
                'organisation' => 'eStab CI',
                'fuehrungsstellenname' => $identifier,
                'einsatzleitung' => 'Automatisierte CI-Einsatzleitung',
                'beschreibung' =>
                    'Isolierter Einsatz für den HTTP-Nachrichtenworkflow.',
                'metadaten' => json_encode(
                    ['zweck' => 'message-workflow-http', 'modus' => $mode],
                    JSON_THROW_ON_ERROR
                ),
            ],
            $actor,
            $shouldActivate,
            $shouldActivate ? (int) $status['revision'] : null,
            $mode === ESTAB_PERMISSION_MODE_LOOSE
        );
        $resultId = (int) $created['einsatz_id'];
    } elseif ($action === 'activate') {
        $incidentId = $positiveId($argv[2] ?? null);
        $incident = estab_incident_find($connection, $incidentId);
        $incidentIdentifier = is_array($incident)
            ? (string) ($incident['kennung'] ?? '')
            : '';
        if (
            !is_array($incident)
            || preg_match(
                '/\ACI-MWF-(?:STRICT|LOOSE)-[A-F0-9]{5}\z/D',
                $incidentIdentifier
            ) !== 1
        ) {
            throw new RuntimeException(
                'Refusing to activate a foreign fixture: incident '
                    . $incidentId . ' has identifier '
                    . json_encode($incidentIdentifier, JSON_THROW_ON_ERROR)
            );
        }
        $status = $activate($connection, $incidentId, $actor);
        $resultId = (int) ($status['active_einsatz_id'] ?? 0);
    } elseif ($action === 'restore') {
        $target = $argv[2] ?? '';
        if ($target === '0') {
            $status = estab_incident_status($connection);
            $activeId = (int) ($status['active_einsatz_id'] ?? 0);
            if ($activeId > 0) {
                $status = estab_incident_deactivate(
                    $connection,
                    $activeId,
                    (int) $status['revision'],
                    $actor
                );
            }
        } else {
            $status = $activate(
                $connection,
                $positiveId($target),
                $actor
            );
        }
        $resultId = (int) ($status['active_einsatz_id'] ?? 0);
    } else {
        throw new InvalidArgumentException('Unknown fixture action');
    }
} finally {
    estab_auth_close($connection);
}

echo $resultId, "\n";
