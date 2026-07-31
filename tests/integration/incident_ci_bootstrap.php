<?php

declare(strict_types=1);

if (getenv('ESTAB_INCIDENT_CI_BOOTSTRAP') !== '1') {
    fwrite(STDERR, "ESTAB_INCIDENT_CI_BOOTSTRAP=1 is required\n");
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
    fwrite(STDERR, "CI database password is required\n");
    exit(2);
}

$databaseConfig = [
    'server' => getenv('ESTAB_DB_HOST') ?: 'db',
    'user' => getenv('ESTAB_DB_USER') ?: 'estab',
    'password' => $password,
    'datenbank' => getenv('ESTAB_DB_NAME') ?: 'estab',
];
unset($password);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = estab_auth_connect($databaseConfig);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$identifier = 'CI-INTEGRATION';
$actor = 'ci-integration';
$commandPostName = 'CI-Führungsstelle Nord';
try {
    $status = estab_incident_status($connection);
    $incidents = array_values(array_filter(
        estab_incident_list($connection),
        static fn (array $incident): bool =>
            ($incident['kennung'] ?? null) === $identifier
    ));
    $assert(
        count($incidents) <= 1,
        'CI incident identifier resolved to more than one incident'
    );

    $activeId = $status['active_einsatz_id'];
    if ($activeId !== null) {
        $assert(
            ($status['kennung'] ?? null) === $identifier,
            'CI bootstrap refuses to replace an already active non-CI incident'
        );
    }

    if ($incidents === []) {
        $created = estab_incident_create(
            $connection,
            [
                'kennung' => $identifier,
                'name' => 'Automatisierter CI-Integrationstest',
                'beginn' => date('Y-m-d\TH:i'),
                'organisation' => 'eStab CI',
                'fuehrungsstellenname' => $commandPostName,
                'beschreibung' =>
                    'Fest benannter Einsatz für operative CI-Schreibtests.',
                'metadaten' => '{"zweck":"ci-integration"}',
            ],
            $actor,
            true,
            (int) $status['revision']
        );
        $incidentId = (int) $created['einsatz_id'];
    } else {
        $incidentId = (int) $incidents[0]['einsatz_id'];
        if ($activeId === null) {
            $status = estab_incident_activate(
                $connection,
                $incidentId,
                (int) $status['revision'],
                $actor
            );
        }
    }

    $active = estab_incident_require_active($connection);
    $assert(
        $active['active_einsatz_id'] === $incidentId
            && ($active['kennung'] ?? null) === $identifier
            && ($active['fuehrungsstellenname'] ?? null) === $commandPostName,
        'CI incident is not the authoritative active incident'
    );

    $revision = (int) $active['revision'];
    $secondActivation = estab_incident_activate(
        $connection,
        $incidentId,
        $revision,
        $actor
    );
    $assert(
        $secondActivation['active_einsatz_id'] === $incidentId
            && (int) $secondActivation['revision'] === $revision,
        'idempotent CI activation changed the incident or status revision'
    );

    $matching = array_values(array_filter(
        estab_incident_list($connection),
        static fn (array $incident): bool =>
            ($incident['kennung'] ?? null) === $identifier
    ));
    $assert(
        count($matching) === 1
            && ($matching[0]['ist_aktiv'] ?? false) === true
            && ($matching[0]['fuehrungsstellenname'] ?? null)
                === $commandPostName,
        'CI incident list does not expose one active named incident'
    );
} finally {
    estab_auth_close($connection);
}

echo "incident CI bootstrap: OK ({$assertions} assertions, "
    . "incident {$incidentId}, revision {$revision})\n";
