<?php

declare(strict_types=1);

/**
 * Request-wide operational write boundary.
 *
 * Every authenticated mutating request that loads the shared database
 * configuration is considered an operational write unless it matches one of
 * the small, explicit recovery/control exceptions below. New endpoints are
 * therefore closed by default without having to remember another allow call.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dv_operations.php';

const ESTAB_OPERATIONAL_WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
const ESTAB_OPERATIONAL_CONTROL_EXCEPTIONS = [
    'administration',
    'backup-recovery',
    'logout',
    'legacy-logout',
    'account-image-recovery',
    'duty-accept',
    'duty-select',
    'handover-confirm',
    'messenger-lifecycle',
    'attachment-cleanup',
];
const ESTAB_OPERATIONAL_MESSENGER_LIFECYCLE_ACTIONS = [
    'accept',
    'deliver',
    'return_path',
    'returned',
];

function estab_operational_request_path(array $server): string
{
    // Authorization boundaries are properties of the script Apache actually
    // selected. REQUEST_URI is attacker-controlled and may append a protected
    // looking segment to another executable (for example
    // mainindex.php/4fadm). SCRIPT_NAME is therefore authoritative; PHP_SELF
    // is only a compatibility fallback for non-Apache SAPIs.
    foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key) {
        $candidate = $server[$key] ?? null;
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }
        $path = parse_url($candidate, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
            if (
                str_contains($path, "\0")
                || preg_match('~(?:^|/)\.{1,2}(?:/|$)~D', $path) === 1
                || preg_match('~(?i)\.php/~D', $path) === 1
            ) {
                return '';
            }
            return $path;
        }
    }
    return '';
}

/** Detect path-info even when a SAPI reports a clean SCRIPT_NAME. */
function estab_operational_request_has_path_info(array $server): bool
{
    foreach (['PATH_INFO', 'ORIG_PATH_INFO'] as $key) {
        $value = $server[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return true;
        }
    }

    $script = $server['SCRIPT_NAME'] ?? null;
    $self = $server['PHP_SELF'] ?? null;
    if (
        is_string($script)
        && $script !== ''
        && is_string($self)
        && $self !== ''
    ) {
        $scriptPath = parse_url($script, PHP_URL_PATH);
        $selfPath = parse_url($self, PHP_URL_PATH);
        if (
            is_string($scriptPath)
            && is_string($selfPath)
            && $selfPath !== $scriptPath
            && str_starts_with($selfPath, rtrim($scriptPath, '/') . '/')
        ) {
            return true;
        }
    }
    return false;
}

function estab_operational_path_ends_with(
    string $path,
    string $applicationPath
): bool {
    return $path === $applicationPath
        || str_ends_with($path, $applicationPath);
}

/**
 * Return the narrowly scoped reason that permits a control/recovery write.
 *
 * Returning null is intentional fail-closed behaviour for every unknown
 * authenticated POST action and every future endpoint.
 */
function estab_operational_control_exception(
    array $server,
    array $post
): ?string {
    if (estab_operational_request_has_path_info($server)) {
        return null;
    }
    $path = estab_operational_request_path($server);
    if (
        preg_match(
            '~(?:^|/)4fadm/(?:[^/]+/)*[^/]+\.php$~iD',
            $path
        ) === 1
    ) {
        return 'administration';
    }
    if (
        preg_match(
            '~(?:^|/)4fbak/(?:[^/]+/)*[^/]+\.php$~iD',
            $path
        ) === 1
    ) {
        return 'backup-recovery';
    }
    if (estab_operational_path_ends_with($path, '/4fach/logout.php')) {
        return 'logout';
    }
    if (
        estab_operational_path_ends_with($path, '/4fach/mainindex.php')
        && array_key_exists('m2_abmelden_x', $post)
    ) {
        return 'legacy-logout';
    }
    if (estab_operational_path_ends_with($path, '/4fach/resetpic.php')) {
        return 'account-image-recovery';
    }
    if (estab_operational_path_ends_with(
        $path,
        '/4fach/fuehrungsstelle.php'
    )) {
        $action = $post['operation_action'] ?? null;
        if ($action === 'accept_hat') {
            return 'duty-accept';
        }
        if ($action === 'select_hat') {
            return 'duty-select';
        }
        if ($action === 'confirm_handover') {
            return 'handover-confirm';
        }
        if (
            $action === 'messenger_transition'
            && is_string($post['transition'] ?? null)
            && in_array(
                $post['transition'],
                ESTAB_OPERATIONAL_MESSENGER_LIFECYCLE_ACTIONS,
                true
            )
        ) {
            return 'messenger-lifecycle';
        }
    }
    if (estab_operational_path_ends_with($path, '/4fach/anhang.php')) {
        $cleanupRequested = array_key_exists('abbrechen_x', $post)
            || array_key_exists('ah_abbrechen_x', $post);
        $writeRequested = array_key_exists('absenden_x', $post)
            || array_key_exists('ah_upload_x', $post)
            || array_key_exists('ah_auswahl_x', $post);
        if ($cleanupRequested && !$writeRequested) {
            return 'attachment-cleanup';
        }
    }
    return null;
}

function estab_operational_write_request(array $server): bool
{
    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    return in_array($method, ESTAB_OPERATIONAL_WRITE_METHODS, true);
}

/** Emit one stable response before any reachable Fachschreibpfad can run. */
function estab_operational_write_abort(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

/**
 * Enforce the request guard at the common database-configuration boundary.
 *
 * Anonymous login requests and independently authenticated administration
 * have no application-session identity and are not operational user writes.
 */
function estab_operational_write_enforce(
    array $databaseConfig,
    array $session,
    array $server,
    array $post
): void {
    static $evaluated = false;

    if ($evaluated || PHP_SAPI === 'cli') {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $evaluated = true;
    if (!estab_operational_write_request($server)) {
        return;
    }
    $identityShape = estab_auth_session_identity_shape($session);
    if ($identityShape === null) {
        return;
    }
    if (estab_operational_control_exception($server, $post) !== null) {
        return;
    }
    // Unlike the pure shape helper, this validates the current SID and
    // returns the exact server-side selected assignment id. A stale session
    // or a request without a selected hat must not reach any Fachschreibpfad.
    $identity = estab_auth_session_identity($session);
    if ($identity === null) {
        estab_operational_write_abort(
            423,
            'Die angemeldete Dienstfunktion ist nicht mehr gültig. '
            . 'Melden Sie sich erneut an und wählen Sie Ihre Funktion.'
        );
    }

    $connection = null;
    try {
        $connection = estab_auth_connect($databaseConfig);
        $incident = estab_incident_active($connection);
        if ($incident === null) {
            throw new EstabDvPermissionException(
                'Operative Eingaben sind gesperrt, weil kein Einsatz aktiv '
                . 'ist. Aktivieren Sie zuerst einen Einsatz in der '
                . 'Administration.'
            );
        }
        estab_incident_command_post_name($incident);
        estab_dv_require_active_hat_for_operational_write(
            $connection,
            (int) $incident['active_einsatz_id'],
            $identity
        );
    } catch (EstabIncidentConfigurationException) {
        estab_operational_write_abort(
            423,
            'Operative Eingaben sind gesperrt, weil für den aktiven Einsatz '
            . 'noch kein Name der Führungsstelle festgelegt wurde. Ergänzen '
            . 'Sie ihn zuerst in der Einsatzverwaltung.'
        );
    } catch (EstabDvPermissionException $exception) {
        estab_operational_write_abort(423, $exception->getMessage());
    } catch (Throwable $exception) {
        error_log(
            'eStab operational write guard unavailable: '
            . $exception->getMessage()
        );
        estab_operational_write_abort(
            503,
            'Die operative Schreibberechtigung kann derzeit nicht geprüft '
            . 'werden. Es wurden keine Eingaben übernommen.'
        );
    } finally {
        if ($connection instanceof mysqli) {
            estab_auth_close($connection);
        }
    }
}
