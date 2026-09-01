<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/dv_operations.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (
    string $exceptionClass,
    callable $operation,
    string $message
) use (&$assertions): void {
    $assertions++;
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }
        throw new RuntimeException(
            $message . ': got ' . $exception::class,
            0,
            $exception
        );
    }
    throw new RuntimeException($message . ': no exception');
};
$read = static function (string $relative): string {
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $source;
};

$expectedMedia = [
    'Fe' => 'Fernsprecher',
    'Fu' => 'Funk',
    'Me' => 'Melder',
    'FAX' => 'Telefax',
    'FS' => 'Fernschreiber',
    '@' => 'Datenübertragung',
];
$assert(
    ESTAB_DV_MEDIA === array_keys($expectedMedia),
    'telecommunications medium storage order changed'
);
foreach ($expectedMedia as $code => $label) {
    $assert(
        estab_dv_telecom_medium_label($code) === $label,
        'telecommunications medium is not expanded: ' . $code
    );
}

/*
 * Der Plan bietet den Fernschreiber nicht mehr an.
 *
 * Das Telexnetz ist abgeschaltet, also ist keine Stelle darueber erreichbar.
 * Der Vordruck behaelt sein gedrucktes Kaestchen und Altnachrichten ihren
 * Wert -- ein Plan fuehrt, was tatsaechlich betrieben wird, und ein Medium
 * ohne Geraet ist kein Weg.
 */
$assert(
    ESTAB_DV_PLAN_MEDIA === ['Fe', 'Fu', 'Me', 'FAX', '@']
        && !in_array('FS', ESTAB_DV_PLAN_MEDIA, true)
        && in_array('FS', ESTAB_DV_MEDIA, true),
    'the plan still offers a medium nobody operates, or lost one it needs'
);
$planMediaOfKinds = array_values(array_unique(array_map(
    static fn (array $kind): string => (string) $kind['medium'],
    ESTAB_DV_TELECOM_ROUTE_KINDS
)));
$assert(
    $planMediaOfKinds === ESTAB_DV_PLAN_MEDIA,
    'route kinds and offered plan media drifted apart'
);

/*
 * Analog fuehrt Kanaele, digital fuehrt Rufgruppen -- beide unter dem Medium
 * Fu, weil das der Wert ist, den Feld 1 des Vordrucks druckt.
 */
$analog = estab_dv_telecom_entry_values([
    'wegart' => 'Fu:ANALOG',
    'betriebsstelle' => 'Führungsstelle',
    'erreichbarkeit' => 'Funkrufname',
    'band' => '4m',
    'kanal' => '55',
    'bandlage' => 'Oberband',
    'verkehrsform' => 'Wechselverkehr',
    'relaisstelle' => 'Rs1',
    'rufgruppe' => 'geschmuggelte Rufgruppe',
    'betriebsart' => 'TMO',
    'datenart' => 'MAIL',
    'bemerkungen' => 'Bemerkung',
]);
$assert(
    $analog['medium'] === 'Fu'
        && $analog['funkart'] === 'ANALOG'
        && $analog['band'] === '4m'
        && $analog['kanal'] === '55'
        && $analog['bandlage'] === 'Oberband'
        && $analog['verkehrsform'] === 'Wechselverkehr'
        && $analog['relaisstelle'] === 'Rs1'
        && $analog['rufgruppe'] === ''
        && $analog['betriebsart'] === null
        && $analog['datenart'] === null,
    'an analogue route kept fields that belong to another technology'
);

$digital = estab_dv_telecom_entry_values([
    'wegart' => 'Fu:DIGITAL',
    'betriebsstelle' => 'Führungsstelle',
    'erreichbarkeit' => 'Funkrufname',
    'betriebsart' => 'DMO',
    'rufgruppe' => '726_B*',
    'kanal' => 'geschmuggelter Kanal',
    'bandlage' => 'geschmuggelte Bandlage',
    'verkehrsform' => 'geschmuggelte Verkehrsform',
    'band' => '2m',
]);
$assert(
    $digital['medium'] === 'Fu'
        && $digital['funkart'] === 'DIGITAL'
        && $digital['betriebsart'] === 'DMO'
        && $digital['rufgruppe'] === '726_B*'
        && $digital['kanal'] === ''
        && $digital['bandlage'] === ''
        && $digital['verkehrsform'] === ''
        && $digital['band'] === null,
    'a digital route carried a channel, band position or traffic form'
);

/*
 * Bandlage und Verkehrsform werden auf Anwesenheit geprueft, nie auf ihren
 * Wert. Die Werteliste dafuer steht in einer eingestuften Vorschrift, die
 * nicht vorliegt; eine hier erfundene wuerde zurueckweisen, was ein Einsatz
 * tatsaechlich benutzt hat.
 */
$freitext = estab_dv_telecom_entry_values([
    'wegart' => 'Fu:ANALOG',
    'betriebsstelle' => 'Führungsstelle',
    'erreichbarkeit' => 'Funkrufname',
    'band' => '2m',
    'kanal' => 'K 31',
    'bandlage' => 'irgendetwas, das keine Liste kennt',
    'verkehrsform' => 'bedingter Gegenverkehr über Rs2',
]);
$assert(
    $freitext['bandlage'] === 'irgendetwas, das keine Liste kennt'
        && $freitext['verkehrsform'] === 'bedingter Gegenverkehr über Rs2',
    'band position or traffic form was checked against a value list'
);

foreach (['Fe' => 'anschlussart', 'FAX' => 'anschlussart'] as $kind => $field) {
    $werte = estab_dv_telecom_entry_values([
        'wegart' => $kind,
        'betriebsstelle' => 'Führungsstelle',
        'erreichbarkeit' => '0228 940-0',
        $field => 'NST',
        'kanal' => 'geschmuggelt',
        'bandlage' => 'geschmuggelt',
        'verkehrsform' => 'geschmuggelt',
    ]);
    $assert(
        $werte['medium'] === $kind
            && $werte['funkart'] === null
            && $werte[$field] === 'NST'
            && $werte['kanal'] === ''
            && $werte['bandlage'] === ''
            && $werte['verkehrsform'] === '',
        'a wire-bound route carried radio fields: ' . $kind
    );
}

$melder = estab_dv_telecom_entry_values([
    'wegart' => 'Me',
    'betriebsstelle' => 'Meldekopf Nord',
    'erreichbarkeit' => 'Sammelstelle Tor 2',
]);
$assert(
    $melder['medium'] === 'Me'
        && $melder['funkart'] === null
        && $melder['kanal'] === ''
        && $melder['verkehrsform'] === '',
    'the messenger route was given technical fields it cannot have'
);

$expect(
    EstabDvInputException::class,
    static fn (): array => estab_dv_telecom_entry_values([
        'wegart' => 'Fu',
        'betriebsstelle' => 'Führungsstelle',
        'erreichbarkeit' => 'Funkrufname',
    ]),
    'a radio route without its kind was accepted'
);
$expect(
    EstabDvInputException::class,
    static fn (): array => estab_dv_telecom_entry_values([
        'wegart' => 'FS',
        'betriebsstelle' => 'Führungsstelle',
        'erreichbarkeit' => 'Fernschreibkennung',
    ]),
    'the plan accepted a telex route'
);
$expect(
    EstabDvInputException::class,
    static fn (): array => estab_dv_telecom_entry_values([
        'wegart' => 'Funk (analog)',
        'betriebsstelle' => 'Führungsstelle',
        'erreichbarkeit' => 'Funkrufname',
    ]),
    'expanded display label was accepted as a storage value'
);
$expect(
    EstabDvInputException::class,
    static fn (): array => estab_dv_telecom_entry_values([
        'wegart' => 'Fu:ANALOG',
        'betriebsstelle' => 'Führungsstelle',
        'erreichbarkeit' => 'Funkrufname',
        'band' => '4m',
        'kanal' => '',
        'bandlage' => 'Oberband',
        'verkehrsform' => 'Wechselverkehr',
    ]),
    'an analogue route without a channel was accepted'
);
$expect(
    EstabDvInputException::class,
    static fn (): array => estab_dv_telecom_entry_values([
        'wegart' => 'Fu:DIGITAL',
        'betriebsstelle' => 'Führungsstelle',
        'erreichbarkeit' => 'Funkrufname',
        'betriebsart' => 'TETRA',
        'rufgruppe' => '726_B*',
    ]),
    'an invented operating mode was accepted'
);
$expect(
    EstabDvInputException::class,
    static fn (): array => estab_dv_telecom_entry_values([
        'wegart' => 'Fu:DIGITAL',
        'betriebsstelle' => 'Führungsstelle',
        'erreichbarkeit' => 'Funkrufname',
        'rufgruppe' => '726_B*',
    ]),
    'a digital route without its operating mode was accepted'
);

/* Ein Altweg ohne Funkart loest zu keiner Wegart auf -- unbestimmt. */
$assert(
    estab_dv_telecom_route_kind('Fu', null) === null
        && estab_dv_telecom_route_kind('Fu', 'ANALOG') === 'Fu:ANALOG'
        && estab_dv_telecom_route_kind('Fe', null) === 'Fe'
        && estab_dv_telecom_route_kind('FS', null) === null,
    'legacy radio routes no longer read as undetermined'
);
/*
 * Eine Gegenstelle traegt zwei Angaben, weil der Vordruck zwei braucht: den
 * Namen der Stelle und die Adresse, unter der sie antwortet. Ein eigenes
 * Medium hat sie NICHT -- das ist das des Wegs, und genau darin liegt die
 * Aussage "ueber DIESEN Weg antwortet JENE Stelle unter DIESER Adresse".
 */
$gegenstelle = estab_dv_telecom_counterpart_values([
    'name' => 'Kreisleitstelle',
    'erreichbarkeit' => 'Leitstelle Kreis',
    'bemerkungen' => 'rund um die Uhr besetzt',
]);
$assert(
    $gegenstelle === [
        'name' => 'Kreisleitstelle',
        'erreichbarkeit' => 'Leitstelle Kreis',
        'bemerkungen' => 'rund um die Uhr besetzt',
    ]
        && !array_key_exists('medium', $gegenstelle)
        && !array_key_exists('funkart', $gegenstelle),
    'a counterpart carries a medium of its own'
);
foreach (['name', 'erreichbarkeit'] as $pflicht) {
    $eingabe = [
        'name' => 'Kreisleitstelle',
        'erreichbarkeit' => 'Leitstelle Kreis',
    ];
    $eingabe[$pflicht] = '';
    $expect(
        EstabDvInputException::class,
        static fn (): array => estab_dv_telecom_counterpart_values($eingabe),
        'a counterpart without ' . $pflicht . ' was accepted'
    );
}
$assert(
    estab_dv_telecom_counterpart_values([
        'name' => 'Kreisleitstelle',
        'erreichbarkeit' => 'Leitstelle Kreis',
    ])['bemerkungen'] === '',
    'a counterpart without a note was refused'
);

/*
 * Die Rueckfallebene ist ein Verweis, kein Schalter mit Ziel. Sie nimmt eine
 * Kennung an oder nichts; ein zweites Wahrheitsfeld gaebe es nur, damit es
 * dem Verweis widersprechen kann.
 */
$mitErsatz = estab_dv_telecom_entry_values([
    'wegart' => 'Fe',
    'betriebsstelle' => 'Kreisleitstelle',
    'erreichbarkeit' => '0228 940-0',
    'rueckfallebene_fuer_weg' => '7',
]);
$assert(
    $mitErsatz['rueckfallebene_fuer_weg'] === 7,
    'the fallback reference was not kept as an identity'
);
$ohneErsatz = estab_dv_telecom_entry_values([
    'wegart' => 'Fe',
    'betriebsstelle' => 'Kreisleitstelle',
    'erreichbarkeit' => '0228 940-0',
    'rueckfallebene_fuer_weg' => '',
]);
$assert(
    $ohneErsatz['rueckfallebene_fuer_weg'] === null,
    'an empty fallback was not read as "no fallback"'
);
$expect(
    EstabDvInputException::class,
    static fn (): array => estab_dv_telecom_entry_values([
        'wegart' => 'Fe',
        'betriebsstelle' => 'Kreisleitstelle',
        'erreichbarkeit' => '0228 940-0',
        'rueckfallebene_fuer_weg' => '0',
    ]),
    'a fallback pointing at no route was accepted'
);
$assert(
    function_exists('estab_dv_telecom_assert_fallback'),
    'the fallback ring check is missing'
);

/* Die Stellenart sagt, in welche Richtung die Verbindung zeigt. */
$mitArt = estab_dv_telecom_entry_values([
    'wegart' => 'Fe',
    'betriebsstelle' => 'Kreisleitstelle',
    'stellenart' => 'UEBER',
    'erreichbarkeit' => '0228 940-0',
]);
$assert(
    $mitArt['stellenart'] === 'UEBER'
        && array_keys(ESTAB_DV_TELECOM_STATION_KINDS)
            === ['EIGEN', 'UEBER', 'UNTER', 'NEBEN'],
    'the station kind was not kept or its value list changed'
);
$ohneArt = estab_dv_telecom_entry_values([
    'wegart' => 'Fe',
    'betriebsstelle' => 'Kreisleitstelle',
    'erreichbarkeit' => '0228 940-0',
]);
$assert(
    $ohneArt['stellenart'] === null,
    'a route without a station kind was not accepted'
);
$expect(
    EstabDvInputException::class,
    static fn (): array => estab_dv_telecom_entry_values([
        'wegart' => 'Fe',
        'betriebsstelle' => 'Kreisleitstelle',
        'stellenart' => 'SEITWAERTS',
        'erreichbarkeit' => '0228 940-0',
    ]),
    'an invented station kind was accepted'
);

$assert(
    estab_dv_telecom_route_label('Fu', null) === 'Funk'
        && estab_dv_telecom_route_label('Fu', 'DIGITAL') === 'Funk (digital)'
        && estab_dv_telecom_route_label('Me', null) === 'Melder',
    'route labels do not name the technology'
);

$token = hash('sha256', 'telecommunications revision fixture');
$assert(
    estab_dv_telecom_revision_token($token) === $token,
    'valid telecommunications revision was rejected'
);
$expect(
    EstabDvInputException::class,
    static fn (): string => estab_dv_telecom_revision_token('../stale'),
    'invalid telecommunications revision was accepted'
);
$headerSnapshot = estab_dv_telecom_plan_header_audit_state([
    'einsatzbezeichnung' => 'Einsatz Nord',
    'herkunft' => 'S6',
    'gueltig_ab' => '2026-08-02 10:00:00',
    'gueltig_bis' => null,
    'betriebsleitung' => 'LdF',
    'bemerkungen' => 'Nur betriebliche Kopfdaten',
    'password' => 'darf-nicht-in-die-evidenz',
    'session_id' => 'darf-ebenfalls-nicht-in-die-evidenz',
]);
$assert(
    array_keys($headerSnapshot) === [
        'einsatzbezeichnung',
        'herkunft',
        'gueltig_ab',
        'gueltig_bis',
        'betriebsleitung',
        'bemerkungen',
    ]
        && !array_key_exists('password', $headerSnapshot)
        && !array_key_exists('session_id', $headerSnapshot),
    'plan header audit snapshot retained credentials or session data'
);

$domain = $read('app/dv_operations.php');
$controller = $read('4fach/fuehrungsstelle.php');
$officialForm = $read('4fach/official_message_form.php');
$legacyForm = $read('4fach/4fachform.php');
$sessionUi = $read('app/session_ui.php');
$styles = $read('estab-ui.css');
$discardMigration = $read(
    'docker/db/migrations/117-telecom-draft-discard.sql'
);

foreach (
    [
        'estab_dv_start_telecom_plan_revision',
        'estab_dv_update_telecom_plan_draft',
        'estab_dv_update_telecom_entry',
        'estab_dv_delete_telecom_entry',
        'estab_dv_discard_telecom_plan_draft',
        'estab_dv_require_telecom_plan_revision',
    ] as $function
) {
    $assert(
        str_contains($domain, 'function ' . $function . '('),
        'missing telecommunications domain operation: ' . $function
    );
}
$assert(
    str_contains($domain, "AND `status` = 'AKTIV' FOR UPDATE")
        && str_contains($domain, 'source_plan_id')
        && str_contains($domain, 'plan_revision_started')
        && str_contains($domain, 'copied_entries')
        && str_contains($domain, 'INSERT INTO `nv_fernmeldeplan_eintraege`')
        && str_contains($domain, 'SELECT ?, `sortierung`, `betriebsstelle`'),
    'active plan is not cloned atomically with source evidence and new entries'
);
$assert(
    str_contains($domain, 'estab_dv_require_no_telecom_draft(')
        && str_contains(
            $domain,
            'Die aktive Fernmeldeplanversion hat sich seit Beginn'
        )
        && str_contains($domain, "['plan_created', 'plan_revision_started']"),
    'parallel or stale telecommunications drafts are not rejected'
);
$createPlanStart = strpos($domain, 'function estab_dv_create_telecom_plan(');
$revisePlanStart = strpos(
    $domain,
    'function estab_dv_start_telecom_plan_revision('
);
$updatePlanStart = strpos($domain, 'function estab_dv_update_telecom_plan_draft(');
$createPlanSource = is_int($createPlanStart) && is_int($revisePlanStart)
    ? substr($domain, $createPlanStart, $revisePlanStart - $createPlanStart)
    : '';
$revisePlanSource = is_int($revisePlanStart) && is_int($updatePlanStart)
    ? substr($domain, $revisePlanStart, $updatePlanStart - $revisePlanStart)
    : '';
$assert(
    str_contains(
        $createPlanSource,
        '// Capture LAST_INSERT_ID before the authority'
    )
        && str_contains(
            $createPlanSource,
            'return (int) $connection->insert_id;'
        )
        && str_contains(
            $revisePlanSource,
            '// Capture LAST_INSERT_ID before the authority'
        )
        && str_contains(
            $revisePlanSource,
            'return (int) $connection->insert_id;'
        ),
    'plan insert IDs are read after the SQL authority context has reset them'
);
$assert(
    str_contains($domain, "AND `status` = 'ENTWURF'")
        && str_contains($domain, 'plan_draft_updated')
        && str_contains($domain, 'plan_entry_updated')
        && str_contains($domain, 'plan_entry_deleted')
        && str_contains($domain, 'plan_draft_discarded')
        && str_contains($domain, "'preserved_entries' => \$entryCount")
        && str_contains($domain, "'before' => \$before"),
    'draft-only edit/delete operations lack structured audit evidence'
);
$integration = $read('tests/integration/dv_operations.php');
$assert(
    str_contains($integration, '--telecom-revision-worker')
        && str_contains($integration, 'proc_open(')
        && str_contains($integration, 'FOR UPDATE')
        && str_contains($integration, 'elapsed_ms')
        && str_contains(
            $integration,
            'two real revision contenders did not wait and serialize to one draft'
        ),
    'telecommunications revision lacks a real two-connection contender proof'
);
$assert(
    str_contains(
        $integration,
        '$secondaryRouteId = estab_dv_add_telecom_entry('
    )
        && substr_count(
            $integration,
            "(int) \$revision['copied_entries'] === 2"
        ) === 1
        && str_contains(
            $integration,
            'array_intersect($sourceEntryIds, $draftEntryIds) === []'
        )
        && str_contains($integration, '$draftEntryStates === $sourceEntryStates')
        && str_contains(
            $integration,
            'estab_dv_telecom_plan_header_audit_state($draftAfterClone)'
        )
        && str_contains(
            $integration,
            "'besondere_vermerke' => 'Priorisierte Führungsverbindung'"
        )
        && str_contains(
            $integration,
            "'bemerkungen' => 'Rückfallebene über Fernsprecher'"
        ),
    'clone integration does not prove two heterogeneous routes, new IDs, '
        . 'optional notes, and complete copied field equality'
);
$assert(
    str_contains($domain, 'estab_dv_telecom_plan_header_audit_state(')
        && substr_count($domain, "'initial_state' =>") >= 2
        && str_contains($domain, "'before' =>")
        && str_contains($domain, "'after' =>")
        && str_contains($domain, 'credentials and session data never enter'),
    'plan header create/update events lack purpose-limited state snapshots'
);
$assert(
    str_contains($domain, 'AS `aktuell_gueltig`')
        && str_contains($domain, 'p.`gueltig_ab` <= NOW()')
        && str_contains($domain, 'p.`gueltig_bis` >= NOW()')
        && !str_contains($domain, 'p.`gueltig_ab` <= NOW(6)')
        && !str_contains($domain, 'p.`gueltig_bis` >= NOW(6)')
        && str_contains($domain, 'zum aktuellen Zeitpunkt nicht ')
        && str_contains($domain, 'Gültigkeitsbeginn oder '),
    'plan activation is not gated by its current validity interval'
);
$assert(
    str_contains($domain, "'erstellt_am' => (string) \$row['erstellt_am']")
        && str_contains(
            $domain,
            "'freigegeben_am' => \$row['freigegeben_am'] === null"
        ),
    'plan history read model omits normalized creation or release timestamps'
);
$assert(
    str_contains($domain, 'ESTAB_DV_LEGACY_AUDIT_MAX_BYTES')
        && str_contains($domain, "'full_details' => 'nv_betriebsereignisse'")
        && str_contains($domain, "'event_sequence' =>")
        && str_contains($domain, "'event_hash' =>")
        && str_contains($domain, "'details_sha256' =>")
        && str_contains($domain, "'details_bytes' =>"),
    'oversized structured audits lack a compact verifiable legacy reference'
);
$assert(
    str_contains($domain, "AND `aktion` = 'plan_activated'")
        && str_contains($domain, "AND `sequenz` < ?")
        && str_contains($domain, '$legacyDraftMatchesActive')
        && str_contains($domain, '$legacySourcePlanId === $previousPlanId'),
    'legacy plan_created drafts are not bound to the activation they followed'
);
$assert(
    substr_count(
        $domain,
        "'actor_function' => (string) \$selected['funktion']"
    ) >= 8,
    'telecommunications audits do not retain the actual LOOSE actor function'
);
$assert(
    str_contains(
        $discardMigration,
        "OLD.`status` = 'ENTWURF' AND NEW.`status` = 'ERSETZT'"
    )
        && str_contains(
            $discardMigration,
            'Discarded telecommunications drafts are immutable evidence'
        )
        && !str_contains($discardMigration, 'DELETE FROM'),
    'discard transition deletes evidence or leaves discarded plans mutable'
);
$assert(
    str_contains(
        $discardMigration,
        'NOT (BINARY OLD.`freigegeben_von` <=>'
    )
        && substr_count(
            $discardMigration,
            'NOT (BINARY OLD.`bemerkungen` <=> BINARY NEW.`bemerkungen`)'
        ) === 3
        && str_contains(
            $discardMigration,
            'OLD.`gueltig_ab` > CURRENT_TIMESTAMP'
        )
        && str_contains(
            $discardMigration,
            'OLD.`gueltig_bis` < CURRENT_TIMESTAMP'
        ),
    'release trigger is NULL-unsafe or admits out-of-window activation'
);
$csrfPosition = strpos(
    $controller,
    'estab_csrf_require_post($_SERVER, $_POST);'
);
$actionPosition = strpos($controller, "if (\$action === 'start_plan_revision')");
$assert(
    is_int($csrfPosition)
        && is_int($actionPosition)
        && $csrfPosition < $actionPosition,
    'telecommunications mutations run before CSRF validation'
);
foreach (
    [
        'start_plan_revision',
        'update_plan',
        'add_plan_entry',
        'update_plan_entry',
        'delete_plan_entry',
        'discard_plan',
        'activate_plan',
    ] as $action
) {
    $assert(
        str_contains($controller, "\$action === '" . $action . "'"),
        'controller action missing: ' . $action
    );
}
$assert(
    str_contains($controller, 'name="plan_revision"')
        && str_contains($controller, 'data-estab-telecom-entry-form')
        // Die Feldmarker entstehen aus dem Katalog, stehen also nicht mehr
        // woertlich im Quelltext. Gepinnt wird deshalb der Mechanismus: die
        // Schleife ueber die Felder und die Liste, die das Skript steuert.
        && str_contains($controller, 'data-estab-telecom-field="<?= ')
        && str_contains($controller, 'data-estab-telecom-kind')
        && str_contains(
            $controller,
            "'band', 'kanal', 'bandlage', 'verkehrsform', 'relaisstelle',"
        )
        && str_contains(
            $controller,
            "'betriebsart', 'rufgruppe', 'anschlussart', 'datenart'"
        )
        && str_contains($controller, 'ESTAB_DV_TELECOM_ROUTE_KINDS')
        && str_contains($controller, 'input.disabled = !visible')
        && str_contains($controller, 'input.required = visible')
        && str_contains($controller, 'art.pflicht.indexOf(fieldName)')
        && str_contains($controller, 'dv_operations_post_value(')
        && str_contains($controller, 'data-estab-telecom-discard-form')
        && str_contains($controller, 'discard-telecom-draft')
        && str_contains($controller, 'data-estab-dirty-guard'),
    'editor lacks stale protection, dynamic fields or failed-input retention'
);
$assert(
    str_contains($controller, 'catch (EstabDvConflictException $exception)')
        && str_contains($controller, '|| $telecomRevisionConflict')
        && str_contains(
            $controller,
            'function dv_operations_posted_telecom_revision_is_stale('
        )
        && str_contains(
            $controller,
            '($plan[\'status\'] ?? null) !== \'ENTWURF\''
        )
        && str_contains(
            $controller,
            '!hash_equals($currentRevision, $postedRevision)'
        )
        && str_contains($controller, '$plansLoaded')
        && str_contains(
            $controller,
            'Der aktuelle gespeicherte Stand wurde neu geladen.'
        )
        && str_contains(
            $controller,
            'Veraltete Eingaben aus diesem Browser-Tab wurden nicht'
        ),
    'freshly loaded revisions do not govern stale POST rehydration'
);
$staleHelperStart = strpos(
    $controller,
    'function dv_operations_posted_telecom_revision_is_stale('
);
$staleHelperEnd = is_int($staleHelperStart)
    ? strpos($controller, 'function dv_operations_failed_post(', $staleHelperStart)
    : false;
$staleHelperMarkup = is_int($staleHelperStart) && is_int($staleHelperEnd)
    ? substr($controller, $staleHelperStart, $staleHelperEnd - $staleHelperStart)
    : '';
$conflictCatchStart = strpos(
    $controller,
    '} catch (EstabDvConflictException $exception) {'
);
$conflictCatchEnd = is_int($conflictCatchStart)
    ? strpos($controller, '} catch (', $conflictCatchStart + 1)
    : false;
$conflictCatchMarkup = is_int($conflictCatchStart) && is_int($conflictCatchEnd)
    ? substr(
        $controller,
        $conflictCatchStart,
        $conflictCatchEnd - $conflictCatchStart
    )
    : '';
$assert(
    $staleHelperMarkup !== ''
        && str_contains(
            $staleHelperMarkup,
            '!hash_equals($currentRevision, $postedRevision)'
        )
        && str_contains(
            $controller,
            'return is_string($value) ? $value : $fallback;'
        ),
    'same-revision validation errors do not retain their submitted values'
);
$assert(
    str_contains($controller, '|| $telecomRevisionConflict')
        && str_contains(
            $controller,
            'dv_operations_posted_telecom_revision_is_stale($plans)'
        )
        && str_contains(
            $controller,
            'Veraltete Eingaben aus diesem Browser-Tab wurden nicht in die '
        ),
    'stale revisions can still overlay submitted values onto the current plan'
);
$assert(
    $conflictCatchMarkup !== ''
        && str_contains(
            $conflictCatchMarkup,
            '$error = $exception->getMessage();'
        )
        && !str_contains($conflictCatchMarkup, '$telecomRevisionConflict')
        && !str_contains($staleHelperMarkup, 'gueltig_ab')
        && !str_contains($staleHelperMarkup, 'gueltig_bis'),
    'invalid plan validity is automatically mislabeled as a stale revision'
);
$assert(
    str_contains($controller, "draft.addEventListener('submit'")
        && str_contains($controller, 'form !== submittedForm && changed(form)')
        && str_contains($controller, 'event.stopPropagation();')
        && str_contains($controller, 'data-estab-telecom-draft-warning')
        && str_contains($controller, 'data-estab-telecom-form-label='),
    'draft actions do not block unsaved changes in other partial forms'
);
$assert(
    str_contains(
        $controller,
        '<option value="" <?= $kind === null ? \'selected\' : \'\' ?>>'
    )
        && str_contains($controller, 'field.disabled) continue;')
        && str_contains($controller, 'field.checked !== field.defaultChecked')
        && str_contains(
            $controller,
            'field.options[optionIndex].defaultSelected'
        )
        && str_contains($controller, 'field.value !== field.defaultValue')
        && !str_contains($controller, 'new WeakMap()')
        && !str_contains($controller, "addEventListener('pageshow'"),
    'dirty state is snapshot from current or browser-restored values'
);
$assert(
    str_contains($controller, 'data-estab-telecom-focus-unsaved')
        && str_contains($controller, 'data-estab-telecom-continue-action')
        && str_contains($controller, 'event.submitter || null')
        && str_contains($controller, 'pendingForm.reportValidity()')
        && str_contains($controller, 'form.requestSubmit(submitter)')
        && str_contains($controller, 'submittedForm === bypassForm')
        && str_contains(
            $controller,
            'Andere Eingaben verwerfen und Aktion fortsetzen'
        )
        && str_contains($sessionUi, 'delete-telecom-entry')
        && str_contains($sessionUi, 'discard-telecom-draft'),
    'multi-form recovery loses the original action or destructive confirms'
);
$bypassStart = strpos($controller, 'submittedForm === bypassForm');
$firstBypassClear = is_int($bypassStart)
    ? strpos($controller, 'bypassForm = null;', $bypassStart + 1)
    : false;
$bypassEnd = is_int($firstBypassClear)
    ? strpos($controller, 'bypassForm = null;', $firstBypassClear + 1)
    : false;
$bypassMarkup = is_int($bypassStart) && is_int($bypassEnd)
    ? substr($controller, $bypassStart, $bypassEnd - $bypassStart)
    : '';
$assert(
    $bypassMarkup !== ''
        && str_contains($bypassMarkup, 'clearPending(true);')
        && str_contains($bypassMarkup, 'return;')
        && !str_contains($bypassMarkup, 'event.preventDefault();')
        && !str_contains($bypassMarkup, 'event.stopPropagation();'),
    'approved retry no longer reaches native delete or discard confirmation'
);
$historyStart = strpos($controller, 'data-estab-telecom-history>');
$historyEnd = is_int($historyStart)
    ? strpos(
        $controller,
        '<section class="estab-tool-panel" id="melderauftraege">',
        $historyStart
    )
    : false;
$historyMarkup = is_int($historyStart) && is_int($historyEnd)
    ? substr($controller, $historyStart, $historyEnd - $historyStart)
    : '';
$assert(
    $historyMarkup !== ''
        && str_contains($historyMarkup, 'Versionshistorie Fernmeldeplan')
        && str_contains($historyMarkup, "? 'replaced'")
        && str_contains($historyMarkup, ": 'discarded'")
        && str_contains($historyMarkup, '$plan[\'erstellt_am\']')
        && str_contains($historyMarkup, '$plan[\'freigegeben_am\']')
        && str_contains($historyMarkup, '<dt>Angelegt</dt>')
        && str_contains($historyMarkup, '<dt>Freigegeben</dt>')
        && str_contains($historyMarkup, 'Nicht freigegeben')
        && str_contains($historyMarkup, '$plan[\'eintraege\']')
        && str_contains($historyMarkup, '$entry[\'besondere_vermerke\']')
        && str_contains($historyMarkup, '$entry[\'bemerkungen\']')
        && str_contains($historyMarkup, 'Besondere Vermerke:')
        && str_contains($historyMarkup, 'Bemerkungen zum Weg:')
        && !str_contains($historyMarkup, '<form'),
    'archived telecommunications versions are not a compact read-only history'
);
$assert(
    str_contains($controller, 'data-estab-telecom-header-remarks')
        && str_contains($controller, 'estab-telecom-plan-note'),
    'active telecommunications plan hides non-empty header remarks'
);
$assert(
    str_contains(
        $sessionUi,
        'gespeicherten Entwurfsdaten bleiben als verworfene Version'
    )
        && str_contains($sessionUi, 'Ungespeicherte Eingaben gehen verloren')
        && !str_contains($sessionUi, 'Alle Entwurfsangaben'),
    'discard confirmation still claims that unsaved browser values are archived'
);
$assert(
    !str_contains($controller, 'foreach (ESTAB_DV_MEDIA as $medium)')
        && !str_contains($controller, 'foreach (ESTAB_DV_PLAN_MEDIA')
        // Der Auswahlkasten fuehrt Wegarten und druckt deren Beschriftung;
        // die Zeilen benennen die Technik mit, nicht nur das Medium.
        && str_contains($controller, "dv_operations_html(\$art['label'])")
        && str_contains($controller, 'estab_dv_telecom_route_label(')
        && str_contains($controller, 'Ausgangsnachricht mit Weg „Melder“')
        && !str_contains($controller, 'Ausgangsnachricht mit Weg „Me“'),
    'telecommunications UI still exposes raw medium codes'
);
$assert(
    // Der Vordruck nennt inzwischen die WEGART statt nur des Mediums:
    // „Funk (digital)“ trennt, was „Fu“ zusammenwarf. Die Zusage bleibt
    // dieselbe -- estab_dv_telecom_route_label() faellt auf die
    // Medienbeschriftung zurueck und gibt in keinem Fall einen Rohcode aus.
    str_contains($officialForm, 'estab_dv_telecom_route_label(')
        && !str_contains($officialForm, 'estab_dv_telecom_medium_label(')
        && str_contains($legacyForm, 'estab_dv_telecom_medium_label ('),
    'message route choices still expose raw telecommunications codes'
);
$assert(
    str_contains($styles, '.estab-telecom-draft')
        && str_contains($styles, '.estab-telecom-plan-meta')
        && str_contains($styles, '.estab-telecom-history-item')
        && str_contains($styles, '.estab-telecom-unsaved')
        && str_contains($styles, '[data-estab-telecom-entry-form] [hidden]'),
    'versioned telecommunications editor lacks responsive UI styling'
);

printf("Telecommunications plan security: OK (%d assertions)\n", $assertions);
