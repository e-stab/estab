<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/read_authorization.php';

$databaseName = getenv('ESTAB_DB_NAME') ?: '';
if ($databaseName !== 'estab_message_suggestions_ci_test') {
    fwrite(
        STDERR,
        "Refusing to run message suggestion integration outside its isolated database\n"
    );
    exit(2);
}

$password = getenv('ESTAB_DB_PASSWORD');
if (!is_string($password) || $password === '') {
    $passwordFile = getenv('ESTAB_DB_PASSWORD_FILE');
    $password = is_string($passwordFile) && is_readable($passwordFile)
        ? trim((string) file_get_contents($passwordFile))
        : '';
}
if ($password === '') {
    fwrite(STDERR, "Message suggestion database password is required\n");
    exit(2);
}
$databaseConfig = [
    'server' => (getenv('ESTAB_DB_HOST') ?: 'db')
        . ':' . (getenv('ESTAB_DB_PORT') ?: '3306'),
    'user' => getenv('ESTAB_DB_USER') ?: 'root',
    'password' => $password,
    'datenbank' => $databaseName,
];
unset($password);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = estab_auth_connect($databaseConfig);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$assertions = 0;
$assert = static function (
    bool $condition,
    string $message
) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectReadDenial = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (EstabReadPermissionException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$expectInvalidInput = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (InvalidArgumentException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$createIncident = static function (
    mysqli $connection,
    string $suffix,
    bool $activate
): array {
    $status = estab_incident_status($connection);
    return estab_incident_create(
        $connection,
        [
            'kennung' => 'SUGGEST-' . $suffix,
            'name' => 'Vorschlagstest ' . $suffix,
            'beginn' => date('Y-m-d\TH:i', time() - 3600),
            'ort' => 'Integrationsprüfung',
            'organisation' => 'THW',
            'einsatzleitung' => 'Testleitung',
            'beschreibung' => 'Isolierter Vorschlagsdatensatz ' . $suffix,
        ],
        'message-suggestion-integration',
        $activate,
        $activate ? (int) $status['revision'] : null
    );
};
$activateIncident = static function (
    mysqli $connection,
    int $incidentId
): void {
    $status = estab_incident_status($connection);
    estab_incident_activate(
        $connection,
        $incidentId,
        (int) $status['revision'],
        'message-suggestion-integration'
    );
};
$insertMessage = static function (
    mysqli $connection,
    int $incidentId,
    string $direction,
    string $callsign,
    string $sender,
    string $marker
): int {
    $statement = $connection->prepare(
        'INSERT INTO `nv_nachrichten`'
        . ' (`einsatz_id`, `04_richtung`, `05_gegenstelle`,'
        . ' `12_inhalt`, `13_abseinheit`, `x00_status`, `x01_abschluss`)'
        . " VALUES (?, ?, ?, ?, ?, 8, 't')"
    );
    if (!$statement) {
        throw new RuntimeException(
            'Could not prepare message suggestion fixture'
        );
    }
    try {
        $statement->bind_param(
            'issss',
            $incidentId,
            $direction,
            $callsign,
            $marker,
            $sender
        );
        $statement->execute();
        return (int) $connection->insert_id;
    } finally {
        $statement->close();
    }
};

try {
    $incidentA = $createIncident($connection, 'A', true);
    $incidentAId = (int) $incidentA['einsatz_id'];
    $insertMessage(
        $connection,
        $incidentAId,
        'E',
        'Nur Einsatz A',
        'Absender Einsatz A',
        'suggestion-history-a'
    );

    $incidentB = $createIncident($connection, 'B', false);
    $incidentBId = (int) $incidentB['einsatz_id'];
    $activateIncident($connection, $incidentBId);
    $insertMessage(
        $connection,
        $incidentBId,
        'A',
        'Nur Einsatz B',
        'Absender Einsatz B',
        'suggestion-history-b'
    );

    $incidentC = $createIncident($connection, 'C', false);
    $incidentCId = (int) $incidentC['einsatz_id'];
    $activateIncident($connection, $incidentCId);

    $fixtureRows = [
        ['E', '  Funkzentrale   Nord  ', ' Einheit & <Nord> ', 'c-1'],
        ['A', 'Ausgang Rufname', 'Ausgang Sender', 'c-2'],
        ['E', 'Doppel Rufname', 'Doppel Sender', 'c-3'],
        ['E', '  Doppel   Rufname ', ' Doppel   Sender ', 'c-4'],
        ['E', '', " \t ", 'c-empty'],
        [
            'E',
            'Rufname & <Leitstelle> "Süd"',
            "Absender O'Brian & Co.",
            'c-special',
        ],
        ['E', 'Bar', '', 'c-accent-base'],
        ['E', 'Bär', '', 'c-accent-umlaut'],
    ];
    foreach ($fixtureRows as [$direction, $callsign, $sender, $marker]) {
        $insertMessage(
            $connection,
            $incidentCId,
            $direction,
            $callsign,
            $sender,
            $marker
        );
    }
    for ($invalidIndex = 0; $invalidIndex < 24; $invalidIndex++) {
        $insertMessage(
            $connection,
            $incidentCId,
            'E',
            "Steuerzeichen\t" . $invalidIndex,
            "\t",
            'c-control-' . $invalidIndex
        );
    }

    $suffix = substr(bin2hex(random_bytes(4)), 0, 4);
    $accounts = [
        's2' => ['a' . $suffix, 'S2', 'Stab'],
        'si' => ['b' . $suffix, 'Si', 'Stab'],
        's6' => ['c' . $suffix, 'S6', 'Stab'],
        'ldf' => ['d' . $suffix, 'LdF', 'Fernmelder'],
        'aw' => ['e' . $suffix, 'A/W', 'Fernmelder'],
    ];
    $insertUser = $connection->prepare(
        'INSERT INTO `nv_benutzer`'
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `aktiv`,'
        . ' `estab_gesperrt`, `password`)'
        . ' VALUES (?, ?, ?, ?, ?, 1, 0, ?)'
    );
    if (!$insertUser) {
        throw new RuntimeException(
            'Could not prepare message suggestion accounts'
        );
    }
    try {
        foreach ($accounts as [$code, $function, $role]) {
            $name = $function . ' Vorschlagsintegration';
            $sessionId = 'suggestion-' . $suffix . '-' . $code;
            $passwordHash = password_hash(
                'suggestion integration ' . $code,
                PASSWORD_DEFAULT
            );
            if (!is_string($passwordHash)) {
                throw new RuntimeException(
                    'Could not hash message suggestion account password'
                );
            }
            $insertUser->bind_param(
                'ssssss',
                $name,
                $code,
                $function,
                $role,
                $sessionId,
                $passwordHash
            );
            $insertUser->execute();
        }
    } finally {
        $insertUser->close();
    }

    $shift = estab_dv_create_shift(
        $connection,
        $incidentCId,
        'Vorschlagsschicht',
        null,
        'message-suggestion-integration'
    );
    $shiftId = (int) $shift['dienstschicht_id'];
    $assignments = [];
    foreach ($accounts as $key => [$code, $function]) {
        $assigned = estab_dv_assign_hat(
            $connection,
            $incidentCId,
            $shiftId,
            $code,
            $function,
            'message-suggestion-integration'
        );
        $assignmentId = (int) $assigned['dienstbesetzung_id'];
        estab_dv_accept_hat(
            $connection,
            $incidentCId,
            $assignmentId,
            $code
        );
        $assignments[$key] = $assignmentId;
    }
    estab_dv_activate_initial_shift(
        $connection,
        $incidentCId,
        $shiftId,
        'message-suggestion-integration'
    );

    $identities = [];
    foreach ($accounts as $key => [$code, $function, $role]) {
        $identities[$key] = [
            'benutzer' => $function . ' Vorschlagsintegration',
            'kuerzel' => $code,
            'funktion' => $function,
            'rolle' => $role,
            'duty_assignment_id' => $assignments[$key],
        ];
    }

    $expectedCallsigns = [
        'Bär',
        'Bar',
        'Rufname & <Leitstelle> "Süd"',
        'Doppel Rufname',
        'Ausgang Rufname',
        'Funkzentrale Nord',
    ];
    $expectedSenders = [
        "Absender O'Brian & Co.",
        'Doppel Sender',
        'Einheit & <Nord>',
    ];
    $ldfCallsigns = estab_read_message_suggestions(
        $connection,
        'nv_nachrichten',
        $identities['ldf'],
        '05_gegenstelle'
    );
    $assert(
        $ldfCallsigns === $expectedCallsigns,
        'LdF callsigns are not active-incident isolated, normalized, '
            . 'deduplicated, and newest-first'
    );
    $assert(
        !in_array('Nur Einsatz A', $ldfCallsigns, true)
            && !in_array('Nur Einsatz B', $ldfCallsigns, true),
        'historical incidents leaked into active-incident callsigns'
    );
    $assert(
        estab_read_message_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['aw'],
            '05_gegenstelle'
        ) === $expectedCallsigns,
        'A/W did not receive the same incident callsign history as LdF'
    );
    $assert(
        estab_read_message_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            '13_abseinheit'
        ) === $expectedSenders,
        'LdF incoming senders are not direction-scoped, normalized, '
            . 'deduplicated, and newest-first'
    );
    $assert(
        !in_array('Ausgang Sender', $expectedSenders, true),
        'sender fixture expectation accidentally includes an outgoing value'
    );
    $assert(
        estab_read_message_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            '05_gegenstelle',
            2
        ) === array_slice($expectedCallsigns, 0, 2),
        'callsign limit is not applied after empty-value removal and deduplication'
    );
    $assert(
        estab_read_message_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            '13_abseinheit',
            1
        ) === [$expectedSenders[0]],
        'sender limit is not applied to the newest valid unique value'
    );
    foreach ([0, 51] as $invalidLimit) {
        $expectInvalidInput(
            static fn (): array => estab_read_message_suggestions(
                $connection,
                'nv_nachrichten',
                $identities['ldf'],
                '05_gegenstelle',
                $invalidLimit
            ),
            'suggestion limit outside 1..50 was accepted: '
                . $invalidLimit
        );
    }
    $assert(
        !in_array('', $ldfCallsigns, true)
            && !in_array(' ', $ldfCallsigns, true),
        'empty callsign values were exposed as suggestions'
    );

    $expectReadDenial(
        static fn (): array => estab_read_message_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['aw'],
            '13_abseinheit'
        ),
        'A/W gained sender history'
    );
    $expectReadDenial(
        static fn (): array => estab_read_message_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['s2'],
            '05_gegenstelle'
        ),
        'S2 gained telecommunications callsign history'
    );
    $expectReadDenial(
        static fn (): array => estab_read_message_suggestions(
            $connection,
            'nv_nachrichten',
            array_diff_key(
                $identities['ldf'],
                ['duty_assignment_id' => true]
            ),
            '05_gegenstelle'
        ),
        'an unselected LdF account gained incident history'
    );
    $forgedLdf = $identities['ldf'];
    $forgedLdf['duty_assignment_id'] = $assignments['aw'];
    $expectReadDenial(
        static fn (): array => estab_read_message_suggestions(
            $connection,
            'nv_nachrichten',
            $forgedLdf,
            '05_gegenstelle'
        ),
        'LdF gained history through another account’s active assignment'
    );

    echo 'message suggestions integration: OK (' . $assertions
        . " assertions)\n";
} finally {
    estab_auth_close($connection);
}
