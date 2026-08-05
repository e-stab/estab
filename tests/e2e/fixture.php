<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('ESTAB_E2E_FIXTURE') !== '1') {
    fwrite(STDERR, "Refusing Playwright fixture mutation\n");
    exit(2);
}

$project = getenv('COMPOSE_PROJECT_NAME');
if (
    !is_string($project)
    || preg_match('/\Aestab_e2e(?:_[a-z0-9_-]+)?\z/D', $project) !== 1
) {
    fwrite(STDERR, "Playwright fixture requires an isolated estab_e2e project\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/app/incident.php';

/** Read one required environment value. */
function estab_e2e_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("Missing Playwright fixture value: {$name}");
    }
    return $value;
}

/** Execute a scalar query with string parameters. */
function estab_e2e_scalar(
    mysqli $connection,
    string $sql,
    string ...$parameters
): string {
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare Playwright verification query');
    }
    try {
        if ($parameters !== []) {
            $types = str_repeat('s', count($parameters));
            $references = [];
            foreach ($parameters as $index => $_value) {
                $references[$index] = &$parameters[$index];
            }
            $statement->bind_param($types, ...$references);
        }
        $statement->execute();
        $row = $statement->get_result()->fetch_row();
        return is_array($row) ? (string) ($row[0] ?? '') : '';
    } finally {
        $statement->close();
    }
}

/** Stop with a verification-focused message. */
function estab_e2e_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Playwright verification failed: ' . $message);
    }
}

$password = getenv('ESTAB_DB_PASSWORD');
if (!is_string($password) || $password === '') {
    $passwordFile = getenv('ESTAB_DB_PASSWORD_FILE');
    $password = is_string($passwordFile) && is_readable($passwordFile)
        ? trim((string) file_get_contents($passwordFile))
        : '';
}
if ($password === '') {
    throw new RuntimeException('Playwright fixture database password is required');
}

$config = [
    'server' => estab_e2e_env('ESTAB_DB_HOST') . ':'
        . estab_e2e_env('ESTAB_DB_PORT'),
    'user' => estab_e2e_env('ESTAB_DB_USER'),
    'password' => $password,
    'datenbank' => estab_e2e_env('ESTAB_DB_NAME'),
];
unset($password);

$action = $argv[1] ?? '';
$marker = estab_e2e_env('ESTAB_E2E_MARKER');
if (preg_match('/\APWE2E_[A-F0-9]{8}\z/D', $marker) !== 1) {
    throw new RuntimeException('Playwright fixture marker is invalid');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = estab_auth_connect($config);

try {
    if ($action === 'prepare') {
        $status = estab_incident_status($connection);
        if (($status['active_einsatz_id'] ?? null) !== null) {
            throw new RuntimeException(
                'Playwright stack is not fresh: an incident is already active'
            );
        }
        $identifier = 'PW-' . substr($marker, 6);
        $created = estab_incident_create(
            $connection,
            [
                'kennung' => $identifier,
                'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
                'name' => 'Playwright Nachrichtenablauf',
                'beginn' => date('Y-m-d\TH:i'),
                'ort' => 'E2E-Testumgebung',
                'organisation' => 'eStab Playwright',
                'fuehrungsstellenname' => 'E2E-Führungsstelle',
                'einsatzleitung' => 'Automatisierte Einsatzleitung',
                'beschreibung' =>
                    'Isolierter strenger Einsatz für den Playwright-Test.',
                'metadaten' => json_encode(
                    ['zweck' => 'playwright-e2e', 'marker' => $marker],
                    JSON_THROW_ON_ERROR
                ),
            ],
            'playwright-fixture',
            true,
            (int) $status['revision'],
            false
        );
        $incidentId = (int) ($created['einsatz_id'] ?? 0);
        estab_e2e_assert($incidentId > 0, 'strict incident was not created');
        echo "Playwright fixture prepared incident {$incidentId}\n";
        exit(0);
    }

    if ($action !== 'verify') {
        throw new InvalidArgumentException('Unknown Playwright fixture action');
    }

    $status = estab_incident_status($connection);
    $incidentId = (int) ($status['active_einsatz_id'] ?? 0);
    estab_e2e_assert($incidentId > 0, 'no active incident remains');
    estab_e2e_assert(
        ($status['estab_permission_mode'] ?? null) === ESTAB_PERMISSION_MODE_STRICT,
        'active incident is not STRICT'
    );

    $shiftSummary = estab_e2e_scalar(
        $connection,
        'SELECT CONCAT(duty_shift.`status`, \'|\','
            . ' COUNT(assignment.`dienstbesetzung_id`),'
            . " '|', SUM(assignment.`status` = 'ANGENOMMEN'))"
            . ' FROM `nv_dienstschichten` AS duty_shift'
            . ' JOIN `nv_dienstbesetzungen` AS assignment'
            . ' ON assignment.`dienstschicht_id` = duty_shift.`dienstschicht_id`'
            . ' WHERE duty_shift.`einsatz_id` = ?'
            . " AND duty_shift.`status` = 'AKTIV'"
            . ' GROUP BY duty_shift.`dienstschicht_id`',
        (string) $incidentId
    );
    estab_e2e_assert(
        $shiftSummary === 'AKTIV|7|7',
        'the active shift does not contain seven accepted functions'
    );

    $expectedFunctions = ['A/W', 'LdF', 'S1', 'S2', 'S3', 'S6', 'Si'];
    $actualFunctions = estab_e2e_scalar(
        $connection,
        'SELECT GROUP_CONCAT(assignment.`funktion`'
            . ' ORDER BY assignment.`funktion` SEPARATOR \',\')'
            . ' FROM `nv_dienstbesetzungen` AS assignment'
            . ' JOIN `nv_dienstschichten` AS duty_shift'
            . ' ON duty_shift.`dienstschicht_id` = assignment.`dienstschicht_id`'
            . ' WHERE duty_shift.`einsatz_id` = ?'
            . " AND duty_shift.`status` = 'AKTIV'"
            . " AND assignment.`status` = 'ANGENOMMEN'",
        (string) $incidentId
    );
    estab_e2e_assert(
        $actualFunctions === implode(',', $expectedFunctions),
        'the accepted function roster is incomplete'
    );

    $routeCount = (int) estab_e2e_scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_fernmeldeplaene` AS plan'
            . ' JOIN `nv_fernmeldeplan_eintraege` AS route'
            . ' ON route.`fernmeldeplan_id` = plan.`fernmeldeplan_id`'
            . ' WHERE plan.`einsatz_id` = ?'
            . " AND plan.`status` = 'AKTIV'"
            . " AND route.`medium` = 'Fu'"
            . " AND route.`rufname` = 'E2E Gegenstelle'",
        (string) $incidentId
    );
    estab_e2e_assert($routeCount === 1, 'the active S6 route is missing');

    $messages = [
        $marker . '-IN' => [
            'direction' => 'E',
            'events' => 'created,ldf_dispatched,incoming_routed',
        ],
        $marker . '-OUT-S1' => [
            'direction' => 'A',
            'events' => 'created,si_approved,ldf_dispatched,aw_transported',
        ],
        $marker . '-OUT-S2' => [
            'direction' => 'A',
            'events' => 'created,si_approved,ldf_dispatched,aw_transported',
        ],
        $marker . '-OUT-S3' => [
            'direction' => 'A',
            'events' => 'created,si_approved,ldf_dispatched,aw_transported',
        ],
    ];

    $incomingId = 0;
    foreach ($messages as $content => $expected) {
        $messageSummary = estab_e2e_scalar(
            $connection,
            'SELECT CONCAT(`00_lfd`, \'|\', `04_richtung`, \'|\','
                . ' `x00_status`, \'|\', `x01_abschluss`)'
                . ' FROM `nv_nachrichten`'
                . ' WHERE `einsatz_id` = ? AND BINARY `12_inhalt` = BINARY ?',
            (string) $incidentId,
            $content
        );
        $parts = explode('|', $messageSummary);
        estab_e2e_assert(
            count($parts) === 4
                && ctype_digit($parts[0])
                && $parts[1] === $expected['direction']
                && $parts[2] === '8'
                && $parts[3] === 't',
            "message {$content} is not completed"
        );
        $messageId = (int) $parts[0];
        if ($expected['direction'] === 'E') {
            $incomingId = $messageId;
            $recipients = estab_e2e_scalar(
                $connection,
                'SELECT `16_empf` FROM `nv_nachrichten` WHERE `00_lfd` = ?',
                (string) $messageId
            );
            foreach (['S1_bl', 'S2_rt', 'S3_bl'] as $recipient) {
                estab_e2e_assert(
                    str_contains($recipients, $recipient),
                    "incoming recipient {$recipient} is missing"
                );
            }
        }
        $events = estab_e2e_scalar(
            $connection,
            'SELECT GROUP_CONCAT(`event_type` ORDER BY `event_id` SEPARATOR \',\')'
                . ' FROM `nv_nachrichten_ereignisse` WHERE `message_id` = ?',
            (string) $messageId
        );
        estab_e2e_assert(
            $events === $expected['events'],
            "message {$content} has the wrong event sequence: {$events}"
        );
        $tbbCount = (int) estab_e2e_scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_tbb`'
                . ' WHERE `einsatz_id` = ? AND `estab_message_id` = ?'
                . " AND `estab_entry_type` = 'nachricht'",
            (string) $incidentId,
            (string) $messageId
        );
        estab_e2e_assert(
            $tbbCount === 1,
            "message {$content} has no unique TBB evidence"
        );
    }

    estab_e2e_assert($incomingId > 0, 'incoming message ID is missing');
    $etbCount = (int) estab_e2e_scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_etb`'
            . ' WHERE `einsatz_id` = ? AND `estab_message_id` = ?'
            . ' AND `etb_aktion` LIKE ?',
        (string) $incidentId,
        (string) $incomingId,
        '%' . $marker . '%'
    );
    estab_e2e_assert(
        $etbCount === 1,
        'S2 did not create the ETB entry linked to the incoming message'
    );

    echo "Playwright verification: OK; strict shift, incoming, ETB and "
        . "three outgoing message paths completed\n";
} finally {
    estab_auth_close($connection);
}
