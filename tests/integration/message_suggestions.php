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
            'estab_permission_mode' => 'LOOSE',
            'beginn' => date('Y-m-d\TH:i', time() - 3600),
            'ort' => 'Integrationsprüfung',
            'organisation' => 'THW',
            'fuehrungsstellenname' => 'Führungsstelle Vorschlag ' . $suffix,
            'einsatzleitung' => 'Testleitung',
            'beschreibung' => 'Isolierter Vorschlagsdatensatz ' . $suffix,
        ],
        'message-suggestion-integration',
        $activate,
        $activate ? (int) $status['revision'] : null,
        true
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
        'message-suggestion-integration',
        true
    );
};
$insertMessage = static function (
    mysqli $connection,
    int $incidentId,
    string $direction,
    string $callsign,
    string $sender,
    string $marker,
    string $address = '',
    int $status = 8,
    string $complete = 't',
    string $lock = 'f',
    string $lockUser = ''
): int {
    $statement = $connection->prepare(
        'INSERT INTO `nv_nachrichten`'
        . ' (`einsatz_id`, `04_richtung`, `05_gegenstelle`,'
        . ' `10_anschrift`, `12_inhalt`, `13_abseinheit`,'
        . ' `x00_status`, `x01_abschluss`, `x02_sperre`,'
        . ' `x03_sperruser`, `x04_druck`)'
        . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 't')"
    );
    if (!$statement) {
        throw new RuntimeException(
            'Could not prepare message suggestion fixture'
        );
    }
    try {
        $statement->bind_param(
            'isssssisss',
            $incidentId,
            $direction,
            $callsign,
            $address,
            $marker,
            $sender,
            $status,
            $complete,
            $lock,
            $lockUser
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
    $assert(
        ($incidentA['estab_permission_mode'] ?? null) === 'LOOSE',
        'suggestion fixture A is not explicitly LOOSE'
    );
    $insertMessage(
        $connection,
        $incidentAId,
        'E',
        'Mapping Rufname',
        'Fremder Absender aus Einsatz A',
        'suggestion-history-a',
        'Mapping Einheit'
    );

    $incidentB = $createIncident($connection, 'B', false);
    $incidentBId = (int) $incidentB['einsatz_id'];
    $assert(
        ($incidentB['estab_permission_mode'] ?? null) === 'LOOSE',
        'suggestion fixture B is not explicitly LOOSE'
    );
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
    $assert(
        ($incidentC['estab_permission_mode'] ?? null) === 'LOOSE',
        'suggestion fixture C is not explicitly LOOSE'
    );
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
        . ' `estab_letzte_aktivitaet`, `estab_gesperrt`, `password`)'
        . ' VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(6), 0, ?)'
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

    $identities = [];
    foreach ($accounts as $key => [$code, $function, $role]) {
        $identities[$key] = [
            'benutzer' => $function . ' Vorschlagsintegration',
            'kuerzel' => $code,
            'funktion' => $function,
            'rolle' => $role,
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
    $forgedLdf = $identities['ldf'];
    $forgedLdf['funktion'] = 'A/W';
    $expectReadDenial(
        static fn (): array => estab_read_message_suggestions(
            $connection,
            'nv_nachrichten',
            $forgedLdf,
            '05_gegenstelle'
        ),
        'LdF gained history through a forged fixed account function'
    );

    // Pair-aware LdF mappings use completed messages first and the currently
    // valid active S6 plan second. The pending current message is locked by the
    // fixed LdF account and supplies its context from the database.
    $plan = estab_dv_create_telecom_plan(
        $connection,
        $incidentCId,
        $identities['s6'],
        [
            'herkunft' => 'Mapping-Integration',
            'gueltig_ab' => date('Y-m-d H:i:s', time() - 3600),
            'gueltig_bis' => date('Y-m-d H:i:s', time() + 3600),
            'betriebsleitung' => 'S6 Vorschlagsintegration',
            'bemerkungen' => 'Pair-aware mapping fixture',
        ]
    );
    $planId = (int) $plan['fernmeldeplan_id'];
    estab_dv_add_telecom_entry(
        $connection,
        $incidentCId,
        $planId,
        $identities['s6'],
        [
            'betriebsstelle' => 'Mapping Einheit',
            // Migration 124 hat `rufname` in `erreichbarkeit` umbenannt, und
            // der Weg traegt seit der Trennung von Analog- und Digitalfunk
            // eine Wegart statt eines blossen Mittels. Analogfunk verlangt
            // zusaetzlich ein Band.
            'erreichbarkeit' => 'Mapping Rufname',
            'wegart' => 'Fu:ANALOG',
            'band' => '2m',
            'kanal' => 'Mapping-1',
            'bandlage' => 'G/U',
            'verkehrsform' => 'Gegenverkehr',
            'besondere_vermerke' => '',
            'bemerkungen' => '',
        ]
    );
    estab_dv_activate_telecom_plan(
        $connection,
        $incidentCId,
        $planId,
        $identities['s6']
    );

    foreach (['pair-in-1', 'pair-in-2'] as $marker) {
        $insertMessage(
            $connection,
            $incidentCId,
            'E',
            'Mapping Rufname',
            'Historischer Absender',
            $marker
        );
    }
    $insertMessage(
        $connection,
        $incidentCId,
        'E',
        'Mapping Rufname',
        'Alternativer Absender',
        'pair-in-alternative'
    );
    $insertMessage(
        $connection,
        $incidentCId,
        'E',
        'Mapping Rufname',
        'Nicht abgeschlossener Absender',
        'pair-in-incomplete',
        '',
        1,
        'f'
    );
    $incomingCurrentId = $insertMessage(
        $connection,
        $incidentCId,
        'E',
        'Mapping Rufname',
        '',
        'pair-in-current',
        '',
        1,
        'f',
        't',
        $accounts['ldf'][0]
    );

    foreach (['pair-out-1', 'pair-out-2'] as $marker) {
        $insertMessage(
            $connection,
            $incidentCId,
            'A',
            'Historischer Rufname',
            'Lokaler Absender',
            $marker,
            'Mapping Einheit'
        );
    }
    $insertMessage(
        $connection,
        $incidentCId,
        'A',
        'Alternativer Rufname',
        'Lokaler Absender',
        'pair-out-alternative',
        'Mapping Einheit'
    );
    $insertMessage(
        $connection,
        $incidentCId,
        'A',
        'Nicht abgeschlossener Rufname',
        'Lokaler Absender',
        'pair-out-incomplete',
        'Mapping Einheit',
        1,
        'f'
    );
    $outgoingCurrentId = $insertMessage(
        $connection,
        $incidentCId,
        'A',
        '',
        'Lokaler Absender',
        'pair-out-current',
        "Mapping Einheit\r\nEinsatzabschnitt",
        1,
        'f',
        't',
        $accounts['ldf'][0]
    );

    $incomingMappings = estab_read_ldf_mapping_suggestions(
        $connection,
        'nv_nachrichten',
        $identities['ldf'],
        $incomingCurrentId,
        'E'
    );
    $assert(
        $incomingMappings === [
            [
                'value' => 'Historischer Absender',
                'source' => 'message',
                'context' => 'Mapping Rufname',
                'match' => 'exact',
                'matched_context' => 'Mapping Rufname',
            ],
            [
                'value' => 'Alternativer Absender',
                'source' => 'message',
                'context' => 'Mapping Rufname',
                'match' => 'exact',
                'matched_context' => 'Mapping Rufname',
            ],
            [
                'value' => 'Mapping Einheit',
                'source' => 'plan',
                'context' => 'Mapping Rufname',
                'match' => 'exact',
                'matched_context' => 'Mapping Rufname',
            ],
        ],
        'incoming LdF mapping did not prefer completed callsign/sender pairs '
            . 'before the active S6 plan'
    );
    $outgoingMappings = estab_read_ldf_mapping_suggestions(
        $connection,
        'nv_nachrichten',
        $identities['ldf'],
        $outgoingCurrentId,
        'A'
    );
    $assert(
        $outgoingMappings === [
            [
                'value' => 'Historischer Rufname',
                'source' => 'message',
                'context' => 'Mapping Einheit Einsatzabschnitt',
                'match' => 'related',
                'matched_context' => 'Mapping Einheit',
            ],
            [
                'value' => 'Alternativer Rufname',
                'source' => 'message',
                'context' => 'Mapping Einheit Einsatzabschnitt',
                'match' => 'related',
                'matched_context' => 'Mapping Einheit',
            ],
            [
                'value' => 'Mapping Rufname',
                'source' => 'plan',
                'context' => 'Mapping Einheit Einsatzabschnitt',
                'match' => 'related',
                'matched_context' => 'Mapping Einheit',
            ],
        ],
        'outgoing LdF mapping did not translate the address context into '
            . 'ranked incident callsigns'
    );
    $assert(
        !in_array(
            'Fremder Absender aus Einsatz A',
            array_column($incomingMappings, 'value'),
            true
        )
            && !in_array(
                'Nicht abgeschlossener Absender',
                array_column($incomingMappings, 'value'),
                true
            )
            && !in_array(
                'Nicht abgeschlossener Rufname',
                array_column($outgoingMappings, 'value'),
                true
        ),
        'foreign-incident or incomplete pairs leaked into LdF mappings'
    );

    // Callsigns are operational identifiers: an incoming mapping must be
    // exact after safe normalization, never a prefix/fuzzy result.
    $insertMessage(
        $connection,
        $incidentCId,
        'E',
        'Prefix Rufname',
        'Prefix Sender',
        'pair-prefix-candidate'
    );
    $prefixCurrentId = $insertMessage(
        $connection,
        $incidentCId,
        'E',
        'Prefix Rufname 21',
        '',
        'pair-prefix-current',
        '',
        1,
        'f',
        't',
        $accounts['ldf'][0]
    );
    $assert(
        estab_read_ldf_mapping_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            $prefixCurrentId,
            'E'
        ) === [],
        'incoming callsign prefix produced a false-positive sender mapping'
    );

    // utf8mb4_unicode_ci considers these pairs equal. The mapping boundary
    // must not: Bär is not Bar and Straße is not Strasse.
    foreach (
        [
            ['Bar', 'Sender Bar', 'pair-collation-bar'],
            ['Bär', 'Sender Bär', 'pair-collation-baer'],
            ['Strasse', 'Sender Strasse', 'pair-collation-strasse'],
            ['Straße', 'Sender Straße', 'pair-collation-sz'],
        ] as [$callsign, $sender, $marker]
    ) {
        $insertMessage(
            $connection,
            $incidentCId,
            'E',
            $callsign,
            $sender,
            $marker
        );
    }
    foreach (
        [
            ['Bär', 'Sender Bär', 'pair-current-baer'],
            ['Straße', 'Sender Straße', 'pair-current-sz'],
        ] as [$callsign, $expectedSender, $marker]
    ) {
        $currentId = $insertMessage(
            $connection,
            $incidentCId,
            'E',
            $callsign,
            '',
            $marker,
            '',
            1,
            'f',
            't',
            $accounts['ldf'][0]
        );
        $mappings = estab_read_ldf_mapping_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            $currentId,
            'E'
        );
        $assert(
            array_column($mappings, 'value') === [$expectedSender]
                && array_column($mappings, 'match') === ['exact'],
            'binary mapping comparison conflated callsign ' . $callsign
        );
    }

    // Legacy escaped contexts and current raw UTF-8 contexts describe the
    // same operational identifier after exactly one entity decode and
    // whitespace compaction.
    $insertMessage(
        $connection,
        $incidentCId,
        'E',
        "  Entity\t &amp;\r\n  &quot;Rufname&quot;  ",
        'Entity Sender',
        'pair-entity-candidate'
    );
    $entityCurrentId = $insertMessage(
        $connection,
        $incidentCId,
        'E',
        'Entity & "Rufname"',
        '',
        'pair-entity-current',
        '',
        1,
        'f',
        't',
        $accounts['ldf'][0]
    );
    $assert(
        estab_read_ldf_mapping_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            $entityCurrentId,
            'E'
        ) === [
            [
                'value' => 'Entity Sender',
                'source' => 'message',
                'context' => 'Entity & "Rufname"',
                'match' => 'exact',
                'matched_context' => 'Entity & "Rufname"',
            ],
        ],
        'legacy entities or internal whitespace broke an exact mapping'
    );
    $insertMessage(
        $connection,
        $incidentCId,
        'E',
        'Double &amp;lt; Context',
        'Double-decoded Sender',
        'pair-double-entity-candidate'
    );
    $doubleEntityCurrentId = $insertMessage(
        $connection,
        $incidentCId,
        'E',
        'Double < Context',
        '',
        'pair-double-entity-current',
        '',
        1,
        'f',
        't',
        $accounts['ldf'][0]
    );
    $assert(
        estab_read_ldf_mapping_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            $doubleEntityCurrentId,
            'E'
        ) === [],
        'legacy mapping context was decoded more than once'
    );

    // Outgoing operating-station annotations are related only at a real word
    // boundary. An arbitrary substring and an accent-insensitive prefix must
    // never become an authoritative callsign suggestion.
    $wordBoundaryCurrentId = $insertMessage(
        $connection,
        $incidentCId,
        'A',
        '',
        'Lokaler Absender',
        'pair-word-boundary-current',
        'Mapping Einheitlich',
        1,
        'f',
        't',
        $accounts['ldf'][0]
    );
    $assert(
        estab_read_ldf_mapping_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            $wordBoundaryCurrentId,
            'A'
        ) === [],
        'outgoing arbitrary substring produced a related callsign mapping'
    );
    $insertMessage(
        $connection,
        $incidentCId,
        'A',
        'Rufname Bar',
        'Lokaler Absender',
        'pair-out-collation-candidate',
        'Bar'
    );
    $accentPrefixCurrentId = $insertMessage(
        $connection,
        $incidentCId,
        'A',
        '',
        'Lokaler Absender',
        'pair-out-collation-current',
        'Bär Einsatzabschnitt',
        1,
        'f',
        't',
        $accounts['ldf'][0]
    );
    $assert(
        estab_read_ldf_mapping_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            $accentPrefixCurrentId,
            'A'
        ) === [],
        'outgoing related mapping used accent-insensitive prefix equality'
    );

    $assert(
        estab_read_ldf_mapping_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            $incomingCurrentId,
            'A'
        ) === [],
        'mapping accepted a direction that does not match the locked message'
    );
    $expectReadDenial(
        static fn (): array => estab_read_ldf_mapping_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['aw'],
            $incomingCurrentId,
            'E'
        ),
        'A/W gained pair-aware LdF mappings'
    );
    $expectReadDenial(
        static fn (): array => estab_read_ldf_mapping_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['s2'],
            $outgoingCurrentId,
            'A'
        ),
        'S2 gained pair-aware LdF mappings'
    );
    foreach ([0, 31] as $invalidMappingLimit) {
        $expectInvalidInput(
            static fn (): array => estab_read_ldf_mapping_suggestions(
                $connection,
                'nv_nachrichten',
                $identities['ldf'],
                $incomingCurrentId,
                'E',
                $invalidMappingLimit
            ),
            'invalid LdF mapping limit was accepted: '
                . $invalidMappingLimit
        );
    }
    $connection->query(
        'UPDATE `nv_nachrichten`'
        . " SET `x02_sperre` = 'f', `x03_sperruser` = ''"
        . ' WHERE `00_lfd` = ' . $incomingCurrentId
    );
    $assert(
        estab_read_ldf_mapping_suggestions(
            $connection,
            'nv_nachrichten',
            $identities['ldf'],
            $incomingCurrentId,
            'E'
        ) === [],
        'a lost LdF message lock still exposed context mappings'
    );

    echo 'message suggestions integration: OK (' . $assertions
        . " assertions)\n";
} finally {
    estab_auth_close($connection);
}
