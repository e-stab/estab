<?php

declare(strict_types=1);

/** Shared, fail-closed incident status for every application surface. */

require_once __DIR__ . '/incident.php';

/**
 * Convert the domain status row into the deliberately small UI state.
 *
 * @return array{
 *   availability:'available',
 *   active:bool,
 *   incident:?array<string,mixed>
 * }
 */
function estab_incident_ui_state_from_status(array $status): array
{
    $activeId = $status['active_einsatz_id'] ?? null;
    if ($activeId === null) {
        return [
            'availability' => 'available',
            'active' => false,
            'incident' => null,
        ];
    }
    $incidentId = estab_incident_positive_id($activeId);
    $code = trim((string) ($status['kennung'] ?? ''));
    $name = trim((string) ($status['name'] ?? ''));
    if ($code === '' || $name === '') {
        throw new RuntimeException(
            'Active incident status is missing its identity'
        );
    }
    return [
        'availability' => 'available',
        'active' => true,
        'incident' => [
            'einsatz_id' => $incidentId,
            'kennung' => $code,
            'name' => $name,
            'fuehrungsstellenname' =>
                $status['fuehrungsstellenname'] ?? null,
            'beginn' => $status['beginn'] ?? null,
            'ort' => $status['ort'] ?? '',
        ],
    ];
}

/**
 * Load the global status once per request.
 *
 * Database or schema failures deliberately become an unavailable state.  The
 * matching UI disables marked operational forms, while server and database
 * guards remain authoritative.
 *
 * @return array<string,mixed>
 */
function estab_incident_ui_current_state(): array
{
    static $state = null;
    if (is_array($state)) {
        return $state;
    }

    $connection = null;
    try {
        $store = estab_auth_runtime_session_store();
        $connection = estab_auth_connect($store['database']);
        $state = estab_incident_ui_state_from_status(
            estab_incident_status($connection)
        );
    } catch (Throwable $exception) {
        error_log(
            'eStab incident status unavailable: ' . $exception->getMessage()
        );
        $state = [
            'availability' => 'unavailable',
            'active' => false,
            'incident' => null,
        ];
    } finally {
        if ($connection instanceof mysqli) {
            estab_auth_close($connection);
        }
    }
    return $state;
}

/** Format a database timestamp for the concise shared status line. */
function estab_incident_ui_datetime(mixed $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y H:i');
    } catch (Throwable) {
        return '';
    }
}

/** Disable only forms explicitly marked as operational when fail-closed. */
function estab_incident_ui_input_guard_script(): string
{
    return '<script data-estab-incident-input-guard>'
        . '(function(){'
        . 'function lock(){'
        . 'var forms=document.querySelectorAll('
        . '\"form[data-estab-requires-incident]\");'
        . 'for(var i=0;i<forms.length;i++){'
        . 'var form=forms[i];'
        . 'form.setAttribute(\"aria-disabled\",\"true\");'
        . 'form.setAttribute(\"data-estab-incident-disabled\",\"true\");'
        . 'var controls=form.querySelectorAll('
        . '\"input,button,select,textarea\");'
        . 'for(var j=0;j<controls.length;j++){controls[j].disabled=true;}'
        . '}}'
        . 'if(document.readyState===\"loading\"){'
        . 'document.addEventListener(\"DOMContentLoaded\",lock,{once:true});'
        . '}else{lock();}'
        . '})();'
        . '</script>';
}

/**
 * Render the active incident or a prominent fail-closed warning.
 *
 * @param array<string,mixed> $state
 */
function estab_incident_ui_markup(
    array $state,
    bool $compact = false,
    bool $sidebar = false
): string {
    $availability = $state['availability'] ?? null;
    $active = ($state['active'] ?? null) === true;
    $class = 'estab-incident-indicator';
    if ($compact) {
        $class .= ' estab-incident-indicator-compact';
    }
    if ($sidebar) {
        $class .= ' estab-incident-indicator-sidebar';
    }

    if ($availability !== 'available') {
        return '<section class="' . $class
            . ' estab-incident-indicator-alert"'
            . ' data-estab-incident-state="unavailable"'
            . ' role="alert" aria-label="Einsatzstatus nicht verfügbar">'
            . '<span class="estab-incident-indicator-label">'
            . 'Einsatzstatus nicht verfügbar</span>'
            . '<strong>Operative Eingaben sind gesperrt.</strong>'
            . '<span>Bitte Datenbank und Migrationen prüfen.</span>'
            . '</section>'
            . estab_incident_ui_input_guard_script();
    }

    if (!$active) {
        $adminUrl = estab_application_url('4fadm/incidents.php');
        return '<section class="' . $class
            . ' estab-incident-indicator-alert"'
            . ' data-estab-incident-state="none"'
            . ' role="alert" aria-label="Kein Einsatz aktiv">'
            . '<span class="estab-incident-indicator-label">'
            . 'Kein Einsatz aktiv</span>'
            . '<strong>Operative Eingaben sind gesperrt.</strong>'
            . '<a href="' . estab_auth_html($adminUrl)
            . '" target="_top">Einsatz anlegen oder aktivieren</a>'
            . '</section>'
            . estab_incident_ui_input_guard_script();
    }

    $incident = $state['incident'] ?? null;
    if (!is_array($incident)) {
        throw new InvalidArgumentException('Active incident UI state is invalid');
    }
    $id = estab_incident_positive_id($incident['einsatz_id'] ?? null);
    $code = trim((string) ($incident['kennung'] ?? ''));
    $name = trim((string) ($incident['name'] ?? ''));
    if ($code === '' || $name === '') {
        throw new InvalidArgumentException('Active incident UI identity is invalid');
    }
    try {
        $commandPostName = estab_incident_command_post_name($incident);
    } catch (EstabIncidentConfigurationException) {
        $adminUrl = estab_application_url('4fadm/incidents.php');
        return '<section class="' . $class
            . ' estab-incident-indicator-alert"'
            . ' data-estab-incident-state="incomplete"'
            . ' data-estab-incident-id="' . $id . '"'
            . ' role="alert" aria-label="Führungsstellenname fehlt">'
            . '<span class="estab-incident-indicator-label">'
            . 'Einsatz unvollständig</span>'
            . '<strong>Name der Führungsstelle fehlt.</strong>'
            . '<span>Operative Eingaben sind gesperrt.</span>'
            . '<a href="' . estab_auth_html($adminUrl)
            . '" target="_top">Führungsstellenname festlegen</a>'
            . '</section>'
            . estab_incident_ui_input_guard_script();
    }
    $details = array_values(array_filter([
        estab_incident_ui_datetime($incident['beginn'] ?? null),
        trim((string) ($incident['ort'] ?? '')),
    ], static fn (string $part): bool => $part !== ''));

    return '<section class="' . $class
        . ' estab-incident-indicator-active"'
        . ' data-estab-incident-state="active"'
        . ' data-estab-incident-id="' . $id . '"'
        . ' data-estab-incident-code="' . estab_auth_html($code) . '"'
        . ' data-estab-command-post-name="'
        . estab_auth_html($commandPostName) . '"'
        . ' aria-label="Aktiver Einsatz">'
        . '<span class="estab-incident-indicator-label">'
        . 'Aktive Führungsstelle</span>'
        . '<strong>' . estab_auth_html($commandPostName) . '</strong>'
        . '<span>Einsatz: ' . estab_auth_html($code)
        . ' · ' . estab_auth_html($name) . '</span>'
        . ($details === []
            ? ''
            : '<span>' . estab_auth_html(implode(' · ', $details)) . '</span>')
        . '</section>';
}
