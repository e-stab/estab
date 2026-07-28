<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/** Capture the validated identity and request data needed after destruction. */
function estab_logout_capture(array $session, array $server, string $sessionId): ?array
{
    $identity = estab_auth_session_identity($session);
    if (
        $identity === null
        || $sessionId === ''
        || strlen($sessionId) > 128
        || preg_match('/[\x00-\x20\x7f]/', $sessionId) === 1
    ) {
        return null;
    }

    return $identity + [
        'sid' => $sessionId,
        'ip' => estab_auth_remote_ip($server),
    ];
}

/** Build the historical semicolon-delimited audit value from validated data. */
function estab_logout_audit_payload(array $snapshot): string
{
    $sessionReference = '';
    $sessionId = $snapshot['sid'] ?? null;
    if (is_string($sessionId) && $sessionId !== '') {
        // Keep the historical correlation field without persisting a reusable
        // browser credential in the audit table.
        $sessionReference = 'sha256:' . hash('sha256', $sessionId);
    }

    return implode(';', [
        (string) ($snapshot['benutzer'] ?? ''),
        (string) ($snapshot['kuerzel'] ?? ''),
        (string) ($snapshot['funktion'] ?? ''),
        (string) ($snapshot['rolle'] ?? ''),
        $sessionReference,
        (string) ($snapshot['ip'] ?? ''),
    ]);
}

/**
 * End the local login first, then update only its matching database session.
 *
 * A persistence failure never keeps the browser authenticated. Binding the
 * update to SID prevents an older tab from deactivating a newer login that
 * happens to use the same account.
 */
function estab_logout_current_session(
    array $databaseConfig,
    string $userTable,
    string $auditTable,
    array $server
): array {
    $snapshot = estab_logout_capture($_SESSION, $server, session_id());
    estab_auth_destroy_session();

    $result = [
        'snapshot' => $snapshot,
        'account_deactivated' => false,
        'audit_recorded' => false,
    ];
    if ($snapshot === null) {
        return $result;
    }

    $connection = null;
    try {
        $connection = estab_auth_connect($databaseConfig);
        $result['account_deactivated'] = estab_auth_mark_logged_out(
            $connection,
            $userTable,
            (string) $snapshot['kuerzel'],
            (string) $snapshot['sid']
        );
        estab_auth_log_event(
            $connection,
            $auditTable,
            'Abmelden',
            estab_logout_audit_payload($snapshot)
        );
        $result['audit_recorded'] = true;
    } catch (Throwable $exception) {
        error_log('eStab logout persistence failed: ' . $exception->getMessage());
    } finally {
        if ($connection instanceof mysqli) {
            estab_auth_close($connection);
        }
    }

    return $result;
}
