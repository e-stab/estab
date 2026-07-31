<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/message_evidence.php';
require_once dirname(__DIR__, 2) . '/app/logbook.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (string $class, callable $operation): bool {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception instanceof $class;
    }
    return false;
};
$root = dirname(__DIR__, 2);
$read = static function (string $path): string {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $contents;
};

$assert(
    estab_message_evidence_datetime(
        '2026-07-30T12:34',
        'Zeit'
    ) === '2026-07-30 12:34:00.000000',
    'minute-precision evidence time was not normalised to DATETIME(6)'
);
$assert(
    estab_message_evidence_datetime(
        '2026-07-30 12:34:56.123456',
        'Zeit'
    ) === '2026-07-30 12:34:56.123456',
    'microsecond evidence time did not round-trip'
);
$assert(
    $throws(
        EstabMessageEvidenceInputException::class,
        static fn (): string => estab_message_evidence_datetime(
            '2026-02-30T12:00',
            'Zeit'
        )
    ),
    'impossible evidence date was accepted'
);

$snapshot = estab_message_evidence_snapshot([
    'z' => 1,
    'a' => ['y' => true, 'x' => null],
]);
$assert(
    $snapshot === '{"a":{"x":null,"y":true},"z":1}',
    'message evidence snapshot is not canonical'
);
$assert(
    hash('sha256', $snapshot)
        === '13821b9bef9a224dc4764c4dd25abf757c94cf1bdafdb4ef8379c28d2e817861',
    'canonical snapshot hash changed unexpectedly'
);

$terminalFixture = [
    'einsatz_id' => '7',
    '00_lfd' => '42',
    '04_nummer' => '9',
    '04_richtung' => 'A',
    '06_befweg' => 'Kanal 404',
    '06_befwegausw' => 'Fu',
    'estab_fernmeldeplan_eintrag_id' => '12',
    '11_rufnummer' => '0711 123456',
    '12_betreff' => 'Lagemeldung',
    '12_inhalt' => 'Unveränderlicher Inhalt',
    '16_empf' => 'S1_rt',
    '17_vermerke' => 'Formal geprüft',
    'x00_status' => '8',
    'x01_abschluss' => 't',
];
$terminalV1 = estab_message_terminal_snapshot(
    $terminalFixture,
    ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
);
$terminalV1Json = estab_message_evidence_snapshot($terminalV1);
$assert(
    !array_key_exists('11_rufnummer', $terminalV1)
        && !array_key_exists('12_betreff', $terminalV1)
        && hash('sha256', $terminalV1Json)
            === 'd037221829cd8a2d9e9ac82c039eebbc80fc598113025f4070dc9a83c270db86',
    'historic V1 terminal snapshot changed after the field extension'
);
$assert(
    estab_message_terminal_snapshot_sha256(
        $terminalFixture,
        ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
    ) === 'd037221829cd8a2d9e9ac82c039eebbc80fc598113025f4070dc9a83c270db86',
    'historic V1 terminal digest no longer round-trips exactly'
);
$terminalV2 = estab_message_terminal_snapshot($terminalFixture);
$terminalV2Digest = estab_message_terminal_snapshot_sha256($terminalFixture);
$assert(
    ESTAB_MESSAGE_TERMINAL_SNAPSHOT_CURRENT
        === ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V2
        && ($terminalV2['11_rufnummer'] ?? null) === '0711 123456'
        && ($terminalV2['12_betreff'] ?? null) === 'Lagemeldung'
        && !hash_equals(
            'd037221829cd8a2d9e9ac82c039eebbc80fc598113025f4070dc9a83c270db86',
            $terminalV2Digest
        ),
    'new terminal snapshots are not V2-bound to number and subject'
);
$assert(
    estab_message_terminal_snapshot_stored_version([])
        === ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
        && estab_message_terminal_snapshot_stored_version([
            'terminal_snapshot_version' => 2,
        ]) === ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V2
        && estab_message_terminal_snapshot_stored_version([
            'terminal_snapshot_version' => '2',
        ]) === null
        && estab_message_terminal_snapshot_stored_version([
            'terminal_snapshot_version' => 3,
        ]) === null,
    'stored terminal snapshot version does not default V1 and fail closed'
);
$terminalV1Digest = estab_message_terminal_snapshot_sha256(
    $terminalV1,
    ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
);
$terminalV1CompatibleLive = array_replace($terminalFixture, [
    '11_rufnummer' => '',
    '12_betreff' => '',
]);
$assert(
    estab_message_terminal_snapshot_embedded_valid(
        $terminalV1,
        $terminalV1Digest,
        ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
    )
        && estab_message_terminal_snapshot_matches_live(
            $terminalV1,
            $terminalV1Digest,
            $terminalV1CompatibleLive,
            ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
        ),
    'V1 evidence no longer supports historic rows with empty extension fields'
);
$assert(
    !estab_message_terminal_snapshot_matches_live(
        $terminalV1,
        $terminalV1Digest,
        array_replace($terminalV1CompatibleLive, [
            '11_rufnummer' => '0711 999999',
        ]),
        ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
    )
        && !estab_message_terminal_snapshot_matches_live(
            $terminalV1,
            $terminalV1Digest,
            array_replace($terminalV1CompatibleLive, [
                '12_betreff' => 'Nicht durch V1 gebundener Betreff',
            ]),
            ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
        ),
    'V1 evidence silently treated populated extension fields as proven'
);
$terminalV1WithUnprotectedField = array_replace($terminalV1, [
    '11_rufnummer' => 'Nicht durch V1 gebunden',
]);
$assert(
    !estab_message_terminal_snapshot_embedded_valid(
        $terminalV1WithUnprotectedField,
        estab_message_terminal_snapshot_sha256(
            $terminalV1WithUnprotectedField,
            ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
        ),
        ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V1
    ),
    'V1 embedded evidence accepted an unhashed extension-field value'
);
$assert(
    estab_message_terminal_snapshot_embedded_valid(
        $terminalV2,
        $terminalV2Digest,
        ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V2
    )
        && estab_message_terminal_snapshot_matches_live(
            $terminalV2,
            $terminalV2Digest,
            $terminalFixture,
            ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V2
        )
        && !estab_message_terminal_snapshot_matches_live(
            $terminalV2,
            $terminalV2Digest,
            array_replace($terminalFixture, [
                '11_rufnummer' => '0711 999999',
            ]),
            ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V2
        )
        && !estab_message_terminal_snapshot_matches_live(
            $terminalV2,
            $terminalV2Digest,
            array_replace($terminalFixture, [
                '12_betreff' => 'Manipulierter Betreff',
            ]),
            ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V2
        ),
    'V2 live binding is blind to number or subject'
);
$terminalV2WithoutSubject = $terminalV2;
unset($terminalV2WithoutSubject['12_betreff']);
$assert(
    !estab_message_terminal_snapshot_embedded_valid(
        $terminalV2WithoutSubject,
        estab_message_terminal_snapshot_sha256(
            $terminalV2WithoutSubject,
            ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V2
        ),
        ESTAB_MESSAGE_TERMINAL_SNAPSHOT_V2
    )
        && $throws(
            EstabMessageEvidenceInputException::class,
            static fn (): array => estab_message_terminal_snapshot(
                $terminalFixture,
                3
            )
        ),
    'incomplete or unknown-version terminal evidence was accepted'
);

$eventHash = estab_message_evidence_event_hash(
    7,
    42,
    'formal_geprueft',
    '2026-07-30 12:34:00.000000',
    '2026-07-30 12:35:01.123456',
    'Test Benutzer',
    'tb',
    'Si',
    1,
    2,
    hash('sha256', $snapshot),
    null
);
$assert(
    $eventHash === '3ac39aced4f00602a8487e2662e9e3fd886f66a94275b13419cdc312e1108ccb',
    'v1 event hash changed unexpectedly'
);
$assert(
    $throws(
        EstabMessageEvidenceInputException::class,
        static fn (): string => estab_message_evidence_event_type('Invalid-Type')
    ),
    'unsafe evidence event type was accepted'
);

$entry = estab_logbook_validate_entry([
    'event' => 'Entscheidung der Einsatzleitung',
    'comment' => 'Begründung dokumentiert',
    'event_time' => '2026-07-30T12:34',
    'event_type' => 'W',
    'message_id' => '42',
    'attachment_id' => '7',
    'reference' => '17',
]);
$assert(
    $entry['valid'] === true
        && $entry['data']['event_time'] === '2026-07-30 12:34:00'
        && $entry['data']['event_type'] === 'W'
        && $entry['data']['message_id'] === 42
        && $entry['data']['attachment_id'] === 7
        && $entry['data']['reference'] === '17',
    'structured ETB entry did not validate'
);
$assert(
    estab_logbook_validate_entry([
        'event' => 'Korrektur ohne Bezug',
        'event_type' => 'korrektur',
        'event_time' => '2026-07-30T12:34',
    ])['valid'] === false,
    'ETB correction without immutable original was accepted'
);
$assert(
    estab_logbook_validate_entry([
        'event' => 'Normaler Eintrag mit verstecktem Korrekturbezug',
        'event_type' => 'ohne',
        'event_time' => '2026-07-30T12:34',
        'correction_of' => '1',
    ])['valid'] === false,
    'non-correction ETB entry accepted a correction target'
);

$migration = $read(
    $root . '/docker/db/migrations/80-dv-evidence-retention.sql'
);
$logbookMigration = $read(
    $root . '/docker/db/migrations/110-etb-tbb-rules.sql'
);
$incident = $read($root . '/app/incident.php');
$evidence = $read($root . '/app/message_evidence.php');
$etb = $read($root . '/stabetb/etb.php');
$admin = $read($root . '/4fadm/incidents.php');

foreach ([
    'nv_nachrichten_ereignisse',
    'nv_nachrichten_nachweiskopf',
    'estab_message_event_hash',
    'previous_event_sha256',
    'snapshot_sha256',
    'event_sha256',
    'schema-migration-80',
    'legacy_import',
    'estab_message_events_bu_append_only',
    'estab_message_events_bd_append_only',
    'estab_incident_events_bu_append_only',
    'estab_incident_events_bd_append_only',
    'estab_etb_bu_einsatz',
    'ETB entries are append-only',
    'estab_retain_until',
    'estab_legal_hold',
] as $needle) {
    $assert(
        str_contains($migration, $needle),
        'DV evidence migration is missing ' . $needle
    );
}
$assert(
    str_contains($migration, 'NEW.`previous_event_sha256` <=> head_hash')
        && str_contains($migration, 'SHA2(NEW.`field_snapshot`, 256)')
        && str_contains($migration, 'NEW.`snapshot_sha256`')
        && str_contains($migration, 'NEW.`event_sha256`')
        && str_contains($migration, 'expected_hash'),
    'database trigger does not verify the complete message hash chain'
);
$assert(
    substr_count($migration, "linked_incident <> NEW.`einsatz_id`") >= 3
        && str_contains(
            $migration,
            'NEW.`estab_correction_of` = NEW.`etb_lfd-nr`'
        )
        && str_contains($migration, "linked_event_type = 'korrektur'")
        && str_contains($migration, 'linked_correction IS NOT NULL'),
    'database trigger permits cross-incident, self, or chained ETB references'
);
$assert(
    str_contains($evidence, 'function estab_message_event_append(')
        && str_contains($evidence, 'function estab_message_evidence_verify(')
        && str_contains($evidence, '@@in_transaction')
        && !str_contains($evidence, 'begin_transaction(')
        && !str_contains($evidence, '->commit()'),
    'message evidence API does not preserve the caller transaction boundary'
);
$assert(
    str_contains($incident, 'function estab_incident_close_preflight(')
        && str_contains($incident, 'function estab_incident_close(')
        && str_contains($incident, 'function estab_incident_set_legal_hold(')
        && str_contains($incident, '`status` IN (2, 8)')
        && str_contains($incident, "DATE_ADD(NOW(6), INTERVAL 10 YEAR)")
        && str_contains($incident, 'estab_message_evidence_verify(')
        && str_contains($incident, 'estab_dv_incident_closure_blockers(')
        && str_contains($incident, "['offene_melderauftraege']"),
    'formal close omits preflight, live reservations, retention, or evidence verification'
);
$assert(
    str_contains($logbookMigration, 'estab_book_lfd')
        && str_contains($logbookMigration, 'Closed incident requires ten-year retention')
        && str_contains($logbookMigration, 'TTB entries are append-only; write a correction')
        && str_contains($logbookMigration, 'TTB entry requires at least one content area'),
    'ETB/TBB migration omits local numbering, ten-year retention, or append-only TBB rules'
);
$assert(
    str_contains($etb, 'Fachliche Ereigniszeit')
        && str_contains($etb, 'name=\\"correction_of\\"')
        && str_contains($etb, 'Berichtigen')
        && !str_contains($etb, 'logbook_action\\" value=\\"delete'),
    'ETB UI does not expose event time and append-only corrections'
);
$assert(
    str_contains($admin, 'admin_action" value="close')
        && str_contains($admin, 'confirm_close')
        && str_contains($admin, 'set_legal_hold')
        && str_contains($admin, 'release_legal_hold'),
    'incident administration omits formal close or legal hold controls'
);

echo "DV evidence security: OK ({$assertions} assertions)\n";
