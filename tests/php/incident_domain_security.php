<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/incident.php';

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
$read = static function (string $path): string {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $contents;
};

$valid = estab_incident_validate_create([
    'kennung' => '  2026-hw_001  ',
    'name' => 'Hochwasser Süd',
    'beginn' => '2026-07-29T08:15',
    'ende' => '2026-07-30T09:45',
    'ort' => 'Kreisgebiet',
    'organisation' => 'Feuerwehr & THW',
    'fuehrungsstellenname' => '  FüSt Süd  ',
    'einsatzleitung' => 'EL Süd',
    'beschreibung' => "Erste Lage\nZweite Zeile",
    'metadaten' => '{"aktenzeichen":"A/42","stufe":2}',
]);
$assert($valid['kennung'] === '2026-HW_001', 'identifier was not canonicalised');
$assert(
    $valid['fuehrungsstellenname'] === 'FüSt Süd',
    'command-post name was not required and normalised'
);
$assert(
    $valid['beginn'] === '2026-07-29 08:15:00'
        && $valid['ende'] === '2026-07-30 09:45:00',
    'incident period was not normalised'
);
$assert(
    json_decode($valid['metadaten'], true, 8, JSON_THROW_ON_ERROR)
        === ['aktenzeichen' => 'A/42', 'stufe' => 2],
    'metadata object did not round-trip'
);

foreach ([
    '',
    'AB',
    ' legacy-import ',
    'LEGACY-CUSTOM',
    'A B C',
    '../ABC',
    'ÄBC',
    str_repeat('A', ESTAB_INCIDENT_IDENTIFIER_MAX_LENGTH + 1),
] as $invalidIdentifier) {
    $assert(
        $throws(
            EstabIncidentInputException::class,
            static fn (): string => estab_incident_identifier($invalidIdentifier)
        ),
        'invalid or reserved incident identifier was accepted'
    );
}
$assert(
    $throws(
        EstabIncidentInputException::class,
        static fn (): array => estab_incident_validate_create([
            'kennung' => 'TEST-001',
            'name' => 'Test',
            'beginn' => '2026-02-30T08:00',
        ])
    ),
    'impossible incident start date was normalised'
);
$assert(
    $throws(
        EstabIncidentInputException::class,
        static fn (): array => estab_incident_validate_create([
            'kennung' => 'TEST-001',
            'name' => 'Test',
            'beginn' => '2026-07-30T08:00',
            'ende' => '2026-07-29T08:00',
        ])
    ),
    'incident end before start was accepted'
);
$assert(
    $throws(
        EstabIncidentInputException::class,
        static fn (): string => estab_incident_metadata('["not","an","object"]')
    ),
    'metadata JSON array was accepted'
);
$assert(
    estab_incident_metadata('') === '{}',
    'empty metadata does not become a valid empty object'
);
$requiredCommandPostFixture = [
    'kennung' => 'TEST-001',
    'name' => 'Test',
    'beginn' => '2026-07-30T08:00',
];
foreach ([null, '', " \t "] as $missingCommandPostName) {
    $assert(
        $throws(
            EstabIncidentInputException::class,
            static fn (): array => estab_incident_validate_create(
                $requiredCommandPostFixture + [
                    'fuehrungsstellenname' => $missingCommandPostName,
                ]
            )
        ),
        'incident creation accepted a missing command-post name'
    );
}
foreach ([
    ['not', 'text'],
    str_repeat('Ä', ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH + 1),
    "FüSt\nNord",
    "FüSt\u{202E}Nord",
    "\u{00A0}\u{2007}",
    "\xC3\x28",
] as $invalidCommandPostName) {
    $assert(
        $throws(
            EstabIncidentInputException::class,
            static fn (): array => estab_incident_validate_create(
                $requiredCommandPostFixture + [
                    'fuehrungsstellenname' => $invalidCommandPostName,
                ]
            )
        ),
        'incident creation accepted an invalid command-post name'
    );
}
$maximumCommandPost = estab_incident_validate_create(
    $requiredCommandPostFixture + [
        'fuehrungsstellenname' => str_repeat(
            'Ä',
            ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH
        ),
    ]
);
$assert(
    estab_auth_text_length($maximumCommandPost['fuehrungsstellenname'])
        === ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH,
    'command-post limit was applied to bytes instead of UTF-8 characters'
);
$assert(
    estab_incident_command_post_name([
        'fuehrungsstellenname' => ' FüSt Einsatz 7 ',
    ]) === 'FüSt Einsatz 7',
    'authoritative command-post name was not normalised'
);
$assert(
    $throws(
        EstabIncidentConfigurationException::class,
        static fn (): string => estab_incident_command_post_name([
            'fuehrungsstellenname' => null,
            'organisation' => 'Nicht als Ersatz verwenden',
            'name' => 'Auch kein Ersatz',
        ])
    ),
    'historical NULL command-post name was silently invented from incident data'
);
$assert(
    $throws(
        EstabIncidentInputException::class,
        static fn (): string => estab_incident_text(
            "single\nline",
            'Single line',
            255,
            false
        )
    ),
    'control character in single-line incident field was accepted'
);
$assert(estab_incident_positive_id('42') === 42, 'valid incident ID rejected');
$assert(estab_incident_revision('0') === 0, 'zero status revision rejected');
foreach (['0', '-1', '01', '1e2', ' 1 '] as $invalidId) {
    $assert(
        $throws(
            EstabIncidentInputException::class,
            static fn (): int => estab_incident_positive_id($invalidId)
        ),
        'non-canonical incident ID was accepted'
    );
}
foreach (['-1', '01', '1e2', ' 1 '] as $invalidRevision) {
    $assert(
        $throws(
            EstabIncidentInputException::class,
            static fn (): int => estab_incident_revision($invalidRevision)
        ),
        'non-canonical status revision was accepted'
    );
}
$assert(
    estab_incident_actor('admin@example.test') === 'admin@example.test',
    'valid administrative identity rejected'
);
$assert(
    $throws(
        EstabIncidentInputException::class,
        static fn (): string => estab_incident_actor("admin\nforged")
    ),
    'unsafe administrative identity accepted'
);

$root = dirname(__DIR__, 2);
$library = $read($root . '/app/incident.php');
$page = $read($root . '/4fadm/incidents.php');
$migration = $read(
    $root . '/docker/db/migrations/50-global-incidents.sql'
);
$ciBootstrap = $read($root . '/tests/integration/incident_ci_bootstrap.php');
$ciPipeline = $read($root . '/tests/integration/ci.sh');

foreach ([
    'estab_incident_active',
    'estab_incident_find',
    'estab_incident_list',
    'estab_incident_create',
    'estab_incident_command_post_name',
    'estab_incident_lock_command_post_for_write',
    'estab_incident_update_command_post_name',
    'estab_incident_activate',
    'estab_incident_deactivate',
    'estab_incident_require_active',
    'estab_incident_with_active_write',
] as $function) {
    $assert(
        str_contains($library, 'function ' . $function . '('),
        'public incident API is missing ' . $function
    );
}
$assert(
    substr_count($library, 'e.`fuehrungsstellenname`') >= 3
        && str_contains(
            $library,
            '`fuehrungsstellenname`, `einsatzleitung`, `beschreibung`'
        )
        && str_contains(
            $library,
            'estab_incident_lock_command_post_for_write($connection, $incident);'
        )
        && str_contains(
            $library,
            'estab_incident_command_post_name($target);'
        ),
    'command-post identity is missing from reads, writes, or activation guards'
);
$assert(
    str_contains($library, 'SELECT s.`active_einsatz_id`')
        && str_contains($library, "if (\$forUpdate) {\n        \$sql .= ' FOR UPDATE';")
        && str_contains($library, '$connection->begin_transaction()')
        && str_contains($library, '$connection->commit()')
        && str_contains($library, '$connection->rollback()'),
    'active incident writes are not protected by a singleton row transaction'
);
$assert(
    str_contains($library, '`revision` = ?')
        && str_contains($library, 'AND `revision` = ?')
        && str_contains($library, 'EstabIncidentConflictException'),
    'stale activation/deactivation forms are not rejected optimistically'
);
$assert(
    substr_count($library, '$connection->prepare(') >= 6
        && substr_count($library, '->bind_param(') >= 6,
    'incident database values are not consistently parameterized'
);
$assert(
    !str_contains($library, 'DELETE FROM `nv_einsaetze`')
        && !str_contains($page, 'admin_action" value="delete'),
    'incident deletion would orphan or erase operational history'
);
$assert(
    str_contains($ciBootstrap, "getenv('ESTAB_INCIDENT_CI_BOOTSTRAP') !== '1'")
        && str_contains($ciBootstrap, "\$identifier = 'CI-INTEGRATION'")
        && str_contains($ciBootstrap, 'estab_incident_create(')
        && str_contains($ciBootstrap, 'estab_incident_activate(')
        && str_contains($ciBootstrap, 'estab_incident_require_active('),
    'CI incident bootstrap does not use the guarded public domain API'
);
$ciBootstrapPosition = strpos(
    $ciPipeline,
    'tests/integration/incident_ci_bootstrap.php'
);
$ciDomainPosition = strpos(
    $ciPipeline,
    'tests/integration/incident_domain.php'
);
$firstOperationalTestPosition = strpos(
    $ciPipeline,
    'tests/integration/attachment_reservation.php'
);
$assert(
    is_int($ciDomainPosition)
        && is_int($ciBootstrapPosition)
        && is_int($firstOperationalTestPosition)
        && $ciDomainPosition < $ciBootstrapPosition
        && $ciBootstrapPosition < $firstOperationalTestPosition
        && str_contains(
            $ciPipeline,
            '--env ESTAB_INCIDENT_CI_BOOTSTRAP=1'
        ),
    'CI does not prove the domain and activate its named incident before writes'
);

$scopeTables = [
    'nv_nachrichten' => 'nachrichten',
    'nv_anhang' => 'anhang',
    'nv_etb' => 'etb',
    'nv_tbb' => 'tbb',
    'nv_ubb' => 'ubb',
    'nv_bhp50' => 'bhp50',
    'nv_komplan' => 'komplan',
    'nv_etbtitel' => 'etbtitel',
    'nv_tbbtitel' => 'tbbtitel',
];
foreach ($scopeTables as $table => $triggerStem) {
    $assert(
        str_contains(
            $migration,
            'ALTER TABLE `' . $table . '`'
        )
            && str_contains(
                $migration,
                'CREATE INDEX IF NOT EXISTS `idx_' . $triggerStem . '_einsatz`'
            ),
        'incident column/index is missing for ' . $table
    );
    foreach (['bi', 'bu', 'bd'] as $timing) {
        $assert(
            str_contains(
                $migration,
                'CREATE TRIGGER `estab_' . $triggerStem . '_'
                    . $timing . '_einsatz`'
            ),
            'strict incident trigger is missing for ' . $table
        );
    }
}
$assert(
    str_contains($migration, 'ALTER TABLE `nv_protokoll`')
        && str_contains(
            $migration,
            'CREATE INDEX IF NOT EXISTS `idx_protokoll_einsatz`'
        )
        && !str_contains(
            $migration,
            'CREATE TRIGGER `estab_protokoll_bi_einsatz`'
        )
        && str_contains($migration, 'global events retain NULL'),
    'global authentication/administration protocol boundary is not explicit'
);
$assert(
    str_contains($migration, "'LEGACY-IMPORT'")
        && str_contains($migration, "'schema-migration-50'")
        && str_contains(
            $migration,
            'Bestandsdaten vor Einführung der Einsatzzuordnung'
        )
        && substr_count(
            $migration,
            'SET `einsatz_id` = legacy_id'
        ) === 10,
    'existing operational rows are not assigned to one reserved legacy incident'
);
$backfillPosition = strpos($migration, 'CALL estab_migrate_50_backfill()');
$triggerFunctionPosition = strpos(
    $migration,
    'CREATE FUNCTION estab_incident_for_insert'
);
$assert(
    is_int($backfillPosition)
        && is_int($triggerFunctionPosition)
        && $backfillPosition < $triggerFunctionPosition,
    'legacy rows could be silently attributed to a newly active incident'
);
$assert(
    str_contains(
        $migration,
        'No active incident: operational insert blocked'
    )
        && str_contains(
            $migration,
            'Operational incident reassignment blocked'
        )
        && str_contains(
            $migration,
            'Operational update targets inactive incident'
        ),
    'database boundary does not fail closed or prevent reassignment'
);
$assert(
    str_contains(
        $migration,
        "COMMENT='estab:migration:50-global-incidents:v1'"
    )
        && str_contains(
            $migration,
            'Incident migration blocked: conflicting incident schema'
        ),
    'interrupted migration retry could adopt unrelated schema'
);

$assert(
    str_contains($page, 'empty($_SERVER[\'REMOTE_USER\'])')
        && str_contains($page, 'estab_csrf_require_post($_SERVER, $_POST)')
        && substr_count($page, 'method="post"') >= 3
        && !preg_match(
            '/method="get"[^>]*admin_action|admin_action[^>]*method="get"/i',
            $page
        ),
    'incident administration is not Basic-Auth, POST, and CSRF protected'
);
$assert(
    str_contains($page, 'data-estab-no-active-incident')
        && str_contains($page, 'alle operativen Eingaben sind gesperrt')
        && str_contains($page, 'activate_immediately')
        && str_contains($page, 'status_revision'),
    'admin page does not explain/implement the global input lock'
);
foreach ([
    'kennung',
    'name',
    'beginn',
    'ende',
    'ort',
    'organisation',
    'fuehrungsstellenname',
    'einsatzleitung',
    'beschreibung',
    'metadaten',
] as $field) {
    $assert(
        str_contains($page, 'name="' . $field . '"'),
        'admin incident metadata field is missing: ' . $field
    );
}
$assert(
    str_contains(
        $page,
        'maxlength="<?= ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH ?>"'
    )
        && str_contains(
            $page,
            'name="expected_fuehrungsstellenname"'
        )
        && str_contains(
            $page,
            '$action === \'update_command_post_name\''
        )
        && str_contains(
            $page,
            'incident_admin_html(' . "\n"
                . '                            $incident[\'fuehrungsstellenname\']'
        )
        && str_contains(
            $page,
            '$incident[\'fuehrungsstellenname_gesperrt\'] ?? 1'
        )
        && str_contains($page, 'data-estab-command-post-readonly')
        && str_contains(
            $page,
            'Er ist nicht über die Bedienoberfläche'
        ),
    'admin command-post input, optimistic update, or HTML boundary is incomplete'
);

echo "incident domain security: OK ({$assertions} assertions)\n";
