<?php

declare(strict_types=1);

if (
    getenv('ESTAB_MESSAGE_SUGGESTION_BROWSER_FIXTURE') !== '1'
    || preg_match(
        '/\Aestab_ci_[a-z0-9_-]+\z/D',
        getenv('COMPOSE_PROJECT_NAME') ?: ''
    ) !== 1
) {
    fwrite(STDERR, "Refusing message-suggestion browser fixture mutation\n");
    exit(2);
}

$action = getenv('ESTAB_MESSAGE_SUGGESTION_FIXTURE_ACTION') ?: '';
$marker = getenv('ESTAB_MESSAGE_SUGGESTION_FIXTURE_MARKER') ?: '';
if (
    !in_array($action, ['create', 'delete'], true)
    || preg_match('/\ABROWSER-GEGENSTELLE-[a-f0-9]{16}\z/D', $marker) !== 1
) {
    fwrite(STDERR, "Invalid message-suggestion browser fixture request\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/4fcfg/dbcfg.inc.php';
require_once dirname(__DIR__, 2) . '/app/incident.php';
require_once dirname(__DIR__, 2) . '/app/message_repository.php';

/** @var array<string,mixed> $conf_4f_db */
$connection = estab_message_connect($conf_4f_db);
try {
    $incident = estab_incident_require_active($connection);
    $incidentId = (int) $incident['active_einsatz_id'];
    $contentMarker = 'browser-suggestion-fixture:' . $marker;

    $delete = $connection->prepare(
        'DELETE FROM `nv_nachrichten`'
        . ' WHERE `einsatz_id` = ?'
        . ' AND BINARY `05_gegenstelle` = BINARY ?'
        . ' AND BINARY `12_inhalt` = BINARY ?'
    );
    if (!$delete) {
        throw new RuntimeException('Could not prepare fixture cleanup');
    }
    try {
        $delete->bind_param('iss', $incidentId, $marker, $contentMarker);
        $delete->execute();
    } finally {
        $delete->close();
    }

    if ($action === 'create') {
        $insert = $connection->prepare(
            'INSERT INTO `nv_nachrichten`'
            . ' (`einsatz_id`, `04_richtung`, `05_gegenstelle`,'
            . ' `12_inhalt`, `13_abseinheit`, `x00_status`, `x01_abschluss`)'
            . " VALUES (?, 'E', ?, ?, '', 8, 't')"
        );
        if (!$insert) {
            throw new RuntimeException('Could not prepare browser fixture');
        }
        try {
            $insert->bind_param('iss', $incidentId, $marker, $contentMarker);
            $insert->execute();
        } finally {
            $insert->close();
        }
    }

    echo 'message suggestion browser fixture: ' . $action . " OK\n";
} finally {
    estab_auth_close($connection);
}
