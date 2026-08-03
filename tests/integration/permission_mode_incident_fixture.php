<?php

declare(strict_types=1);

if (
    PHP_SAPI !== 'cli'
    || getenv('ESTAB_PERMISSION_MODE_INCIDENT_FIXTURE') !== '1'
    || preg_match(
        '/\Aestab_ci(?:_[a-z0-9_-]+)?\z/D',
        getenv('ESTAB_PERMISSION_MODE_INCIDENT_PROJECT') ?: ''
    ) !== 1
) {
    fwrite(STDERR, "Refusing permission-mode incident fixture mutation\n");
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
    fwrite(STDERR, "Permission-mode fixture database password is required\n");
    exit(2);
}

$databaseConfig = [
    'server' => getenv('ESTAB_DB_HOST') ?: 'db',
    'user' => getenv('ESTAB_DB_USER') ?: 'estab',
    'password' => $password,
    'datenbank' => getenv('ESTAB_DB_NAME') ?: 'estab',
];
unset($password);

$positiveId = static function (mixed $value): int {
    if (
        !is_string($value)
        || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1
    ) {
        throw new InvalidArgumentException('Invalid fixture incident ID');
    }
    return (int) $value;
};

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = estab_auth_connect($databaseConfig);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $action = $argv[1] ?? '';
    $actor = 'permission-mode-integration-fixture';
    if ($action === 'create-loose') {
        $identifier = $argv[2] ?? '';
        if (
            preg_match(
                '/\ACI-(?:ADM|HTTP)-LOOSE-[0-9]{1,10}-[0-9]{1,10}\z/D',
                $identifier
            ) !== 1
        ) {
            throw new InvalidArgumentException('Invalid fixture identifier');
        }
        $status = estab_incident_status($connection);
        $created = estab_incident_create(
            $connection,
            [
                'kennung' => $identifier,
                'estab_permission_mode' => ESTAB_PERMISSION_MODE_LOOSE,
                'name' => 'HTTP-Integration LOOSE',
                'beginn' => date('Y-m-d\TH:i'),
                'ort' => 'Integrationsprüfung',
                'organisation' => 'eStab CI',
                'fuehrungsstellenname' => $identifier,
                'einsatzleitung' => 'Automatisierte CI-Einsatzleitung',
                'beschreibung' =>
                    'Isolierter LOOSE-Einsatz für HTTP-Integrationsprüfungen.',
                'metadaten' => json_encode(
                    ['zweck' => 'permission-mode-http-integration'],
                    JSON_THROW_ON_ERROR
                ),
            ],
            $actor,
            true,
            (int) $status['revision'],
            true
        );
        $resultId = (int) $created['einsatz_id'];
    } elseif ($action === 'restore') {
        $incidentId = $positiveId($argv[2] ?? null);
        $incident = estab_incident_find($connection, $incidentId);
        if (!is_array($incident) || ($incident['estab_status'] ?? null) !== 'open') {
            throw new RuntimeException('Restore incident is unavailable');
        }
        $status = estab_incident_status($connection);
        $restored = estab_incident_activate(
            $connection,
            $incidentId,
            (int) $status['revision'],
            $actor,
            ($incident['estab_permission_mode'] ?? null)
                === ESTAB_PERMISSION_MODE_LOOSE
        );
        $resultId = (int) ($restored['active_einsatz_id'] ?? 0);
    } else {
        throw new InvalidArgumentException('Unknown fixture action');
    }
} finally {
    estab_auth_close($connection);
}

echo $resultId, "\n";
