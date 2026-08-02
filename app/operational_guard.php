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
require_once __DIR__ . '/workflow.php';

const ESTAB_OPERATIONAL_WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
const ESTAB_OPERATIONAL_CONTROL_EXCEPTIONS = [
    'administration',
    'backup-recovery',
    'logout',
    'session-activity',
    'legacy-logout',
    'account-image-recovery',
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
    if (estab_operational_path_ends_with($path, '/4fach/activity.php')) {
        return 'session-activity';
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
    $context = function_exists('estab_navigation_active_key')
        ? estab_navigation_active_key($_SERVER)
        : null;
    if (
        is_string($context)
        && function_exists('estab_session_ui_abort')
        && isset($_SESSION)
        && is_array($_SESSION)
    ) {
        estab_session_ui_abort(
            $_SESSION,
            $status,
            $status === 503
                ? 'Berechtigungsprüfung vorübergehend nicht verfügbar'
                : 'Eingabe derzeit nicht möglich',
            $message,
            $context
        );
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

/**
 * Discard a stale message-controller POST and return to the explicit login.
 *
 * The common guard runs while dbcfg.inc.php is loaded, before mainindex.php
 * can apply its ordinary anonymous-request redirect. Keep this exception
 * exact to that controller and to the same-site form shape already accepted
 * by the controller. No submitted value is copied or replayed.
 */
function estab_operational_redirect_stale_message_post(
    array $server,
    array $get,
    array $post
): bool {
    if (
        estab_operational_request_has_path_info($server)
        || !estab_operational_path_ends_with(
            estab_operational_request_path($server),
            '/4fach/mainindex.php'
        )
        || !estab_workflow_anonymous_operational_post(
            $server,
            $get,
            $post
        )
    ) {
        return false;
    }
    header('Cache-Control: no-store');
    header('Vary: Cookie, Sec-Fetch-Site');
    header(
        'Location: ' . estab_navigation_login_content_url(
            'messages',
            true
        ),
        true,
        303
    );
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
    array $post,
    array $get = []
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
    // Unlike the pure shape helper, this validates the current SID, fixed
    // account function and optional access-shift gate. A stale or disabled
    // session must not reach a fachlicher Schreibpfad.
    $identity = estab_auth_session_identity($session);
    if ($identity === null) {
        estab_operational_redirect_stale_message_post(
            $server,
            $get,
            $post
        );
        estab_operational_write_abort(
            423,
            'Die Anmeldung oder der Benutzerzugang ist nicht mehr gültig. '
            . 'Melden Sie sich erneut an.'
        );
    }

    $connection = null;
    $writeError = null;
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
        estab_dv_require_operational_account(
            $connection,
            (int) $incident['active_einsatz_id'],
            $identity
        );
    } catch (EstabIncidentConfigurationException) {
        $writeError = [
            423,
            'Operative Eingaben sind gesperrt, weil für den aktiven Einsatz '
            . 'noch kein Name der Führungsstelle festgelegt wurde. Ergänzen '
            . 'Sie ihn zuerst in der Einsatzverwaltung.'
        ];
    } catch (EstabDvPermissionException $exception) {
        $writeError = [423, $exception->getMessage()];
    } catch (Throwable $exception) {
        error_log(
            'eStab operational write guard unavailable: '
            . $exception->getMessage()
        );
        $writeError = [
            503,
            'Die operative Schreibberechtigung kann derzeit nicht geprüft '
            . 'werden. Es wurden keine Eingaben übernommen.'
        ];
    } finally {
        if ($connection instanceof mysqli) {
            estab_auth_close($connection);
        }
    }
    if (is_array($writeError)) {
        estab_operational_write_abort($writeError[0], $writeError[1]);
    }
}
