<?php

declare(strict_types=1);

/**
 * Prepare the disposable real-browser proof for inactive messenger dispatch.
 *
 * The guarded fixture leaves the assigned operational record as evidence. It
 * mutates only purpose-made CI accounts and revokes the exact synthetic SID
 * during cleanup. A genuine or replaced session is never overwritten.
 */

require_once dirname(__DIR__, 2) . '/app/dv_operations.php';

function inactive_messenger_fixture_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException(
            "Missing inactive-messenger browser fixture value: {$name}"
        );
    }
    return $value;
}

function inactive_messenger_fixture_account(
    mysqli $connection,
    string $code,
    string $function,
    string $role
): array {
    $statement = $connection->prepare(
        'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `ip`,'
        . ' `fwdip`, `aktiv`, `estab_gesperrt`, `estab_letzte_aktivitaet`,'
        . ' `password` FROM `nv_benutzer`'
        . ' WHERE BINARY `kuerzel` = BINARY ?'
        . ' AND BINARY `funktion` = BINARY ?'
        . ' AND BINARY `rolle` = BINARY ? LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare fixture account lookup');
    }
    try {
        $statement->bind_param('sss', $code, $function, $role);
        $statement->execute();
        $account = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (
        !is_array($account)
        || (int) ($account['estab_gesperrt'] ?? 1) !== 0
    ) {
        throw new RuntimeException(
            'Fixture account is missing, blocked, or has the wrong function'
        );
    }
    return $account;
}

function inactive_messenger_fixture_require_signed_out(
    array $account,
    string $label
): void {
    if (
        (int) ($account['aktiv'] ?? 0) !== 0
        || (string) ($account['sid'] ?? '') !== ''
    ) {
        throw new RuntimeException(
            "Refusing to replace an existing {$label} session"
        );
    }
}

function inactive_messenger_fixture_synthetic_sid(
    string $project,
    string $marker
): string {
    $sessionId = 'fixture-' . substr(
        hash('sha256', $project . '|' . $marker . '|online-messenger'),
        0,
        32
    );
    if (!estab_auth_session_id_is_valid($sessionId)) {
        throw new RuntimeException('Synthetic fixture SID is invalid');
    }
    return $sessionId;
}

function inactive_messenger_fixture_active_me_route(
    mysqli $connection,
    int $incidentId,
    array $identity,
    string $marker
): int {
    $plans = estab_dv_telecom_plans($connection, $incidentId);
    $active = null;
    foreach ($plans as $plan) {
        if (($plan['status'] ?? null) !== 'AKTIV') {
            continue;
        }
        $active = $plan;
        foreach ($plan['eintraege'] as $entry) {
            if (($entry['medium'] ?? null) === 'Me') {
                return (int) $entry['fernmeldeplan_eintrag_id'];
            }
        }
    }

    foreach ($plans as $plan) {
        if (($plan['status'] ?? null) === 'ENTWURF') {
            estab_dv_discard_telecom_plan_draft(
                $connection,
                $incidentId,
                (int) $plan['fernmeldeplan_id'],
                $identity,
                (string) $plan['revision']
            );
        }
    }

    if (is_array($active)) {
        $draft = estab_dv_start_telecom_plan_revision(
            $connection,
            $incidentId,
            (int) $active['fernmeldeplan_id'],
            $identity
        );
    } else {
        $draft = estab_dv_create_telecom_plan(
            $connection,
            $incidentId,
            $identity,
            [
                'herkunft' => 'Browser-Fixture ' . $marker,
                'gueltig_ab' => date('Y-m-d H:i:s', time() - 3600),
                'gueltig_bis' => date('Y-m-d H:i:s', time() + 86400),
                'betriebsleitung' => 'S6 Browser-Fixture',
                'bemerkungen' => 'Guarded inactive-messenger acceptance',
            ]
        );
    }

    $planId = (int) $draft['fernmeldeplan_id'];
    $draftPlan = null;
    foreach (estab_dv_telecom_plans($connection, $incidentId) as $plan) {
        if ((int) $plan['fernmeldeplan_id'] === $planId) {
            $draftPlan = $plan;
            break;
        }
    }
    if (!is_array($draftPlan) || $draftPlan['status'] !== 'ENTWURF') {
        throw new RuntimeException('Fixture telecommunications draft is missing');
    }
    $entryId = estab_dv_add_telecom_entry(
        $connection,
        $incidentId,
        $planId,
        $identity,
        [
            'betriebsstelle' => 'Browser-Melderweg',
            'rufname' => 'Browser-Melder',
            'medium' => 'Me',
            'kanal' => '',
            'bandlage' => '',
            'verkehrsform' => 'Persönliche Beförderung',
            'besondere_vermerke' => '',
            'bemerkungen' => $marker,
        ],
        'nv_protokoll',
        (string) $draftPlan['revision']
    );
    $draftPlan = null;
    foreach (estab_dv_telecom_plans($connection, $incidentId) as $plan) {
        if ((int) $plan['fernmeldeplan_id'] === $planId) {
            $draftPlan = $plan;
            break;
        }
    }
    if (!is_array($draftPlan)) {
        throw new RuntimeException('Updated fixture plan is missing');
    }
    estab_dv_activate_telecom_plan(
        $connection,
        $incidentId,
        $planId,
        $identity,
        'nv_protokoll',
        (string) $draftPlan['revision']
    );
    return $entryId;
}

if (getenv('ESTAB_INACTIVE_MESSENGER_BROWSER_FIXTURE') !== '1') {
    throw new RuntimeException(
        'Inactive-messenger browser fixture mutation flag is required'
    );
}
$project = inactive_messenger_fixture_env('COMPOSE_PROJECT_NAME');
if (
    $project !== 'estab_ci'
    && preg_match('/\Aestab_ci_[a-z0-9_-]+\z/D', $project) !== 1
) {
    throw new RuntimeException(
        'Refusing inactive-messenger fixture outside a disposable project'
    );
}
$action = inactive_messenger_fixture_env(
    'ESTAB_INACTIVE_MESSENGER_FIXTURE_ACTION'
);
if (!in_array($action, ['create', 'cleanup'], true)) {
    throw new RuntimeException('Unknown inactive-messenger fixture action');
}
$marker = inactive_messenger_fixture_env(
    'ESTAB_INACTIVE_MESSENGER_FIXTURE_MARKER'
);
if (preg_match('/\ABROWSER-MELDER-[a-f0-9]{16}\z/D', $marker) !== 1) {
    throw new RuntimeException('Invalid inactive-messenger fixture marker');
}
$ldfCode = strtolower(inactive_messenger_fixture_env(
    'ESTAB_INACTIVE_MESSENGER_LDF_CODE'
));
$inactiveCode = strtolower(inactive_messenger_fixture_env(
    'ESTAB_INACTIVE_MESSENGER_SIGNED_OUT_CODE'
));
$onlineCode = strtolower(inactive_messenger_fixture_env(
    'ESTAB_INACTIVE_MESSENGER_ONLINE_CODE'
));
foreach ([$ldfCode, $inactiveCode, $onlineCode] as $code) {
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1) {
        throw new RuntimeException('Invalid fixture account code');
    }
}
if (count(array_unique([$ldfCode, $inactiveCode, $onlineCode])) !== 3) {
    throw new RuntimeException('Fixture account codes must be distinct');
}

$databasePassword = getenv('ESTAB_DB_PASSWORD');
if (!is_string($databasePassword) || $databasePassword === '') {
    $passwordFile = getenv('ESTAB_DB_PASSWORD_FILE');
    $databasePassword = is_string($passwordFile) && is_readable($passwordFile)
        ? trim((string) file_get_contents($passwordFile))
        : '';
}
if ($databasePassword === '') {
    throw new RuntimeException('Fixture database password is required');
}
$config = [
    'server' => inactive_messenger_fixture_env('ESTAB_DB_HOST')
        . ':' . inactive_messenger_fixture_env('ESTAB_DB_PORT'),
    'user' => inactive_messenger_fixture_env('ESTAB_DB_USER'),
    'password' => $databasePassword,
    'datenbank' => inactive_messenger_fixture_env('ESTAB_DB_NAME'),
];
unset($databasePassword);

$connection = estab_auth_connect($config);
$syntheticSessionId = inactive_messenger_fixture_synthetic_sid(
    $project,
    $marker
);
$syntheticSessionActivated = false;
$temporaryS6Grant = false;
$createSucceeded = false;
try {
    $incident = estab_incident_status($connection);
    $incidentId = $incident['active_einsatz_id'] ?? null;
    if (!is_int($incidentId) || $incidentId < 1) {
        throw new RuntimeException('Fixture requires an active incident');
    }
    if (($incident['estab_permission_mode'] ?? null) !== 'LOOSE') {
        throw new RuntimeException('Fixture requires explicit LOOSE mode');
    }
    estab_permission_context_set_from_incident($incident);

    $ldfAccount = inactive_messenger_fixture_account(
        $connection,
        $ldfCode,
        'LdF',
        'Fernmelder'
    );
    $inactiveAccount = inactive_messenger_fixture_account(
        $connection,
        $inactiveCode,
        'A/W',
        'Fernmelder'
    );
    $onlineAccount = inactive_messenger_fixture_account(
        $connection,
        $onlineCode,
        'A/W',
        'Fernmelder'
    );

    if ($action === 'cleanup') {
        $storedSession = (string) ($onlineAccount['sid'] ?? '');
        if (
            $storedSession !== ''
            && !hash_equals($syntheticSessionId, $storedSession)
        ) {
            throw new RuntimeException(
                'Refusing to revoke a non-fixture messenger session'
            );
        }
        if (
            $storedSession !== ''
            && !estab_auth_mark_logged_out(
                $connection,
                'nv_benutzer',
                $onlineCode,
                $syntheticSessionId
            )
        ) {
            throw new RuntimeException('Synthetic fixture SID cleanup failed');
        }
        $cleanupGrant = $connection->prepare(
            'DELETE FROM `nv_benutzer_zusatzfunktionen`'
            . ' WHERE BINARY `benutzer_kuerzel` = BINARY ?'
            . " AND BINARY `funktion` = BINARY 'S6'"
            . " AND BINARY `rolle` = BINARY 'Stab'"
            . ' AND BINARY `vergeben_von` = BINARY ?'
        );
        if (!$cleanupGrant) {
            throw new RuntimeException('Could not prepare fixture grant cleanup');
        }
        try {
            $cleanupGrant->bind_param('ss', $onlineCode, $marker);
            $cleanupGrant->execute();
        } finally {
            $cleanupGrant->close();
        }
        echo "inactive messenger browser fixture: cleanup OK\n";
        return;
    }

    inactive_messenger_fixture_require_signed_out($ldfAccount, 'LdF');
    inactive_messenger_fixture_require_signed_out(
        $inactiveAccount,
        'inactive messenger'
    );
    inactive_messenger_fixture_require_signed_out(
        $onlineAccount,
        'online messenger'
    );

    estab_auth_update_user(
        $connection,
        'nv_benutzer',
        [
            'benutzer' => (string) $onlineAccount['benutzer'],
            'kuerzel' => $onlineCode,
            'funktion' => 'A/W',
            'rolle' => 'Fernmelder',
            'sid' => $syntheticSessionId,
            'ip' => '127.0.0.1',
            'fwdip' => '',
            'password' => (string) $onlineAccount['password'],
        ]
    );
    $syntheticSessionActivated = true;

    $activeRouteId = null;
    foreach (estab_dv_telecom_plans($connection, $incidentId) as $plan) {
        if (($plan['status'] ?? null) !== 'AKTIV') {
            continue;
        }
        foreach ($plan['eintraege'] as $entry) {
            if (($entry['medium'] ?? null) === 'Me') {
                $activeRouteId = (int) $entry['fernmeldeplan_eintrag_id'];
                break 2;
            }
        }
    }
    if ($activeRouteId === null) {
        $grant = $connection->prepare(
            'INSERT INTO `nv_benutzer_zusatzfunktionen`'
            . ' (`benutzer_kuerzel`, `funktion`, `rolle`, `vergeben_von`)'
            . " VALUES (?, 'S6', 'Stab', ?)"
        );
        if (!$grant) {
            throw new RuntimeException('Could not prepare temporary S6 grant');
        }
        try {
            $grant->bind_param('ss', $onlineCode, $marker);
            $grant->execute();
            if ($grant->affected_rows !== 1) {
                throw new RuntimeException('Temporary S6 grant was not created');
            }
            $temporaryS6Grant = true;
        } finally {
            $grant->close();
        }
        $activeRouteId = inactive_messenger_fixture_active_me_route(
            $connection,
            $incidentId,
            [
                'benutzer' => (string) $onlineAccount['benutzer'],
                'kuerzel' => $onlineCode,
                'funktion' => 'S6',
                'rolle' => 'Stab',
            ],
            $marker
        );
    }
    if (!is_int($activeRouteId) || $activeRouteId < 1) {
        throw new RuntimeException('Fixture messenger route is invalid');
    }

    $duplicate = $connection->prepare(
        'SELECT COUNT(*) AS `fixture_count` FROM `nv_nachrichten`'
        . ' WHERE `einsatz_id` = ? AND BINARY `12_betreff` = BINARY ?'
    );
    if (!$duplicate) {
        throw new RuntimeException('Could not prepare fixture duplicate check');
    }
    try {
        $duplicate->bind_param('is', $incidentId, $marker);
        $duplicate->execute();
        $fixtureCount = (int) (
            $duplicate->get_result()->fetch_assoc()['fixture_count'] ?? 0
        );
    } finally {
        $duplicate->close();
    }
    if ($fixtureCount !== 0) {
        throw new RuntimeException('Inactive-messenger fixture already exists');
    }

    $numberResult = $connection->query(
        'SELECT COALESCE(MAX(`04_nummer`), 0) + 1 AS `next_number`'
        . ' FROM `nv_nachrichten`'
        . ' WHERE `einsatz_id` = ' . $incidentId
        . " AND `04_richtung` = 'A'"
    );
    if (!$numberResult) {
        throw new RuntimeException('Could not allocate fixture message number');
    }
    try {
        $messageNumber = (int) (
            $numberResult->fetch_assoc()['next_number'] ?? 0
        );
    } finally {
        $numberResult->free();
    }
    if ($messageNumber < 1) {
        throw new RuntimeException('Fixture message number is invalid');
    }
    $messageAddress = $marker;
    $messageContent = 'Guarded inactive-messenger browser acceptance';
    $insertMessage = $connection->prepare(
        'INSERT INTO `nv_nachrichten`'
        . ' (`einsatz_id`, `04_richtung`, `04_nummer`, `06_befweg`,'
        . ' `06_befwegausw`, `estab_fernmeldeplan_eintrag_id`,'
        . ' `10_anschrift`, `12_betreff`, `12_inhalt`, `13_abseinheit`,'
        . ' `x00_status`, `x01_abschluss`)'
        . " VALUES (?, 'A', ?, 'Melder', 'Me', ?, ?, ?, ?, '', 2, 'f')"
    );
    if (!$insertMessage) {
        throw new RuntimeException('Could not prepare fixture message');
    }
    try {
        $insertMessage->bind_param(
            'iiisss',
            $incidentId,
            $messageNumber,
            $activeRouteId,
            $messageAddress,
            $marker,
            $messageContent
        );
        $insertMessage->execute();
        if ((int) $connection->insert_id < 1) {
            throw new RuntimeException('Fixture message was not created');
        }
    } finally {
        $insertMessage->close();
    }
    $createSucceeded = true;
    echo "inactive messenger browser fixture: create OK\n";
} finally {
    try {
        if ($temporaryS6Grant) {
            $deleteGrant = $connection->prepare(
                'DELETE FROM `nv_benutzer_zusatzfunktionen`'
                . ' WHERE BINARY `benutzer_kuerzel` = BINARY ?'
                . " AND BINARY `funktion` = BINARY 'S6'"
                . " AND BINARY `rolle` = BINARY 'Stab'"
                . ' AND BINARY `vergeben_von` = BINARY ?'
            );
            if (!$deleteGrant) {
                throw new RuntimeException(
                    'Could not prepare temporary S6 grant cleanup'
                );
            }
            try {
                $deleteGrant->bind_param('ss', $onlineCode, $marker);
                $deleteGrant->execute();
                if ($deleteGrant->affected_rows !== 1) {
                    throw new RuntimeException(
                        'Temporary S6 grant cleanup did not match one row'
                    );
                }
            } finally {
                $deleteGrant->close();
            }
        }
        if (
            $action === 'create'
            && !$createSucceeded
            && $syntheticSessionActivated
            && !estab_auth_mark_logged_out(
                $connection,
                'nv_benutzer',
                $onlineCode,
                $syntheticSessionId
            )
        ) {
            throw new RuntimeException(
                'Failed fixture did not revoke its synthetic SID'
            );
        }
    } finally {
        estab_auth_close($connection);
    }
}
