<?php

/**
 * Request and role boundary for the historical main controller.
 *
 * The legacy controller performs many actions in one file. Keeping the
 * admission decision pure and central makes it possible to prove that no
 * request reaches those branches before a valid application login.
 */

require_once __DIR__ . '/auth.php';

/** Return true only for the exact anonymous requests used by the login UI. */
function estab_workflow_public_login_request(array $server, array $get, array $post): bool
{
    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        return $get === [] && $post === [];
    }
    if ($method !== 'POST' || $get !== [] || $post === []) {
        return false;
    }

    $allowedKeys = [
        'login', 'login_x', 'login_y',
        'login_identity',
        'benutzer', 'kuerzel', 'funktion',
        'kennwort1', 'kennwort2', '2teskennwort',
        'absenden_x', 'absenden_y',
    ];
    foreach (array_keys($post) as $key) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
            return false;
        }
    }

    $coordinatePairValid = static function (array $values): bool {
        if (
            !array_key_exists('login_x', $values)
            || !array_key_exists('login_y', $values)
        ) {
            return false;
        }
        foreach (['login_x', 'login_y'] as $key) {
            if (
                !is_string($values[$key])
                || preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/D', $values[$key]) !== 1
            ) {
                return false;
            }
        }
        return true;
    };

    if (array_key_exists('login', $post)) {
        $extraKeys = array_diff(array_keys($post), ['login', 'login_x', 'login_y']);
        $hasCoordinates = isset($post['login_x']) || isset($post['login_y']);
        return is_string($post['login'])
            && $post['login'] === 'Anmelden'
            && $extraKeys === []
            && (!$hasCoordinates || $coordinatePairValid($post));
    }

    if (
        array_keys($post) === ['login_x', 'login_y']
        || array_keys($post) === ['login_y', 'login_x']
    ) {
        return $coordinatePairValid($post);
    }

    if (array_key_exists('login_identity', $post)) {
        return is_string($post['login_identity'])
            && $post['login_identity'] !== ''
            && array_keys($post) === ['login_identity'];
    }

    foreach (['benutzer', 'kuerzel', 'funktion', 'kennwort1'] as $requiredKey) {
        if (!isset($post[$requiredKey]) || !is_string($post[$requiredKey])) {
            return false;
        }
    }
    if (isset($post['2teskennwort'])) {
        if (!is_string($post['2teskennwort']) || !in_array($post['2teskennwort'], ['Yes', 'No'], true)) {
            return false;
        }
        if ($post['2teskennwort'] === 'Yes' && !isset($post['kennwort2'])) {
            return false;
        }
    }
    return !isset($post['login'], $post['login_identity']);
}

/** Send the same non-disclosing denial used by data-bearing endpoints. */
function estab_workflow_forbid(): never
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Aktion nicht erlaubt.';
    exit;
}

/** Parse a positive SQL record identifier without coercion. */
function estab_workflow_record_id(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
        return null;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
    ]);
    return is_int($parsed) ? $parsed : null;
}

/** Accept category navigation only as a positive primary key or "alle". */
function estab_workflow_category_filter(mixed $value): int|string|null
{
    if ($value === 'alle') {
        return 'alle';
    }
    return estab_workflow_record_id($value);
}

function estab_workflow_is_telecommunications(array $identity): bool
{
    return ($identity['funktion'] ?? '') === 'A/W'
        && ($identity['rolle'] ?? '') === 'Fernmelder';
}

function estab_workflow_is_viewer(array $identity): bool
{
    return ($identity['funktion'] ?? '') === 'Si'
        && ($identity['rolle'] ?? '') === 'Stab';
}

function estab_workflow_is_staff_writer(array $identity): bool
{
    return ($identity['funktion'] ?? '') !== 'Si'
        && in_array(($identity['rolle'] ?? ''), ['Stab', 'FB'], true);
}

/**
 * Reject unknown image-button coordinates before the legacy controller can
 * accidentally grow a new action branch outside this central policy.
 */
function estab_workflow_action_keys_allowed(array $request): bool
{
    $allowed = array_fill_keys([
        'login_x', 'login_y',
        'absenden_x', 'absenden_y',
        'abbrechen_x', 'abbrechen_y',
        'antwort_x', 'antwort_y',
        'weiterleiten_x', 'weiterleiten_y',
        'gelesen_x', 'gelesen_y',
        'anhang_plus_x', 'anhang_plus_y',
        'stab_anhang_x', 'stab_anhang_y',
        'fm_anhang_x', 'fm_anhang_y',
        'ah_auswahl_x', 'ah_auswahl_y',
        'ah_upload_x', 'ah_upload_y',
        'stab_schreiben_x', 'stab_schreiben_y',
        'stab_lesen_x', 'stab_lesen_y',
        'stab_sichten_x', 'stab_sichten_y',
        'fm_eingang_x', 'fm_eingang_y',
        'fm_ausgang_x', 'fm_ausgang_y',
        'fm_admin_x', 'fm_admin_y',
        'si_admin_x', 'si_admin_y',
        'm2_benutzer_x', 'm2_benutzer_y',
        'm2_abmelden_x', 'm2_abmelden_y',
        'filter_darstellung_aus_x', 'filter_darstellung_aus_y',
        'filter_darstellung_ein_x', 'filter_darstellung_ein_y',
        'filter_erledigt_aus_x', 'filter_erledigt_aus_y',
        'filter_erledigt_ein_x', 'filter_erledigt_ein_y',
        'filter_unerledigt_aus_x', 'filter_unerledigt_aus_y',
        'filter_unerledigt_ein_x', 'filter_unerledigt_ein_y',
        'flt_find_mask_aus_x', 'flt_find_mask_aus_y',
        'flt_find_mask_ein_x', 'flt_find_mask_ein_y',
        'filter_anzahl_x', 'filter_anzahl_y',
        'flt_start_x', 'flt_start_y',
        'flt_back_x', 'flt_back_y',
        'flt_for_x', 'flt_for_y',
        'flt_end_x', 'flt_end_y',
        'etb_eintrag_x', 'etb_eintrag_y',
    ], true);

    foreach (array_keys($request) as $key) {
        if (!is_string($key)) {
            return false;
        }
        if (
            (str_ends_with($key, '_x') || str_ends_with($key, '_y'))
            && !isset($allowed[$key])
        ) {
            return false;
        }
    }
    return true;
}

/** Return the object-level permission required by this request, if any. */
function estab_workflow_message_operation(array $request): ?string
{
    if (array_key_exists('reset_record', $request)) {
        return 'telecommunications-reset';
    }
    if (isset($request['action'])) {
        return 'staff-state';
    }

    $task = (string) ($request['task'] ?? '');
    if ($task !== '') {
        return match ($task) {
            'Stab_lesen' => 'staff-read',
            'Stab_sichten' => 'viewer-review',
            'FM-Ausgang', 'FM-Ausgang_Sichter' => 'telecommunications-save',
            'FM-Admin' => 'telecommunications-admin',
            'SI-Admin' => 'viewer-admin',
            default => null,
        };
    }

    if (($request['stab'] ?? '') === 'meldung') {
        return 'staff-read';
    }
    if (($request['sichter'] ?? '') === 'meldung') {
        return 'viewer-review';
    }
    return match ((string) ($request['fm'] ?? '')) {
        'meldung' => 'telecommunications-edit',
        'FM-Adminmeldung' => 'telecommunications-admin',
        'SI-Adminmeldung' => 'viewer-admin',
        default => null,
    };
}

/**
 * Authorise every route selector whose legacy branch can read or change
 * message state. Unrecognised task values fail closed.
 */
function estab_workflow_route_allowed(array $identity, string $method, array $request): bool
{
    $method = strtoupper($method);
    $isTelecommunications = estab_workflow_is_telecommunications($identity);
    $isViewer = estab_workflow_is_viewer($identity);
    $isStaffWriter = estab_workflow_is_staff_writer($identity);

    if (!estab_workflow_action_keys_allowed($request)) {
        return false;
    }
    // The historical parameter editor is not present in this tree and must
    // never be reachable merely by inventing its old image-button parameter.
    if (
        array_key_exists('m2_parameter_x', $request)
        || array_key_exists('m2_parameter_y', $request)
    ) {
        return false;
    }

    if (array_key_exists('reset_record', $request)) {
        if (
            $method !== 'POST'
            || !$isTelecommunications
            || estab_workflow_record_id($request['reset_record']) === null
        ) {
            return false;
        }
    }

    if (isset($request['task']) && (string) $request['task'] !== '') {
        if ($method !== 'POST' || !is_string($request['task'])) {
            return false;
        }
        $task = $request['task'];
        $allowed = match ($task) {
            'Stab_schreiben', 'Stab_gesprnoti', 'Stab_lesen' => $isStaffWriter,
            'Stab_sichten', 'SI-Admin' => $isViewer,
            'FM-Ausgang', 'FM-Ausgang_Sichter', 'FM-Admin',
            'FM-Eingang', 'FM-Eingang_Sichter',
            'FM-Eingang_Anhang', 'FM-Eingang_Anhang_Sichter' => $isTelecommunications,
            default => false,
        };
        if (!$allowed) {
            return false;
        }
    }

    if (($request['stab'] ?? '') === 'meldung') {
        if (
            $method !== 'POST'
            || !$isStaffWriter
            || estab_workflow_record_id($request['00_lfd'] ?? null) === null
        ) {
            return false;
        }
    }
    if (($request['sichter'] ?? '') === 'meldung') {
        if (
            $method !== 'POST'
            || !$isViewer
            || estab_workflow_record_id($request['00_lfd'] ?? null) === null
        ) {
            return false;
        }
    }
    if (isset($request['fm']) && (string) $request['fm'] !== '') {
        if (!is_string($request['fm'])) {
            return false;
        }
        $fmAllowed = match ($request['fm']) {
            'meldung', 'FM-Adminmeldung' => $isTelecommunications,
            'SI-Adminmeldung' => $isViewer,
            default => false,
        };
        if (
            !$fmAllowed
            || $method !== 'POST'
            || estab_workflow_record_id($request['00_lfd'] ?? null) === null
        ) {
            return false;
        }
    }

    $roleButtons = [
        'stab_schreiben_x' => $isStaffWriter,
        'stab_lesen_x' => $isStaffWriter,
        'stab_anhang_x' => $isStaffWriter,
        'fm_eingang_x' => $isTelecommunications,
        'fm_ausgang_x' => $isTelecommunications,
        'fm_admin_x' => $isTelecommunications,
        'fm_anhang_x' => $isTelecommunications,
        'stab_sichten_x' => $isViewer,
        'si_admin_x' => $isViewer,
    ];
    foreach ($roleButtons as $key => $allowed) {
        if (array_key_exists($key, $request) && !$allowed) {
            return false;
        }
    }

    if (isset($request['action'])) {
        if (
            $method !== 'POST'
            || !$isStaffWriter
            || !is_string($request['action'])
            || !in_array($request['action'], ['gelesen', 'erledigt'], true)
            || !isset($request['todo'])
            || !is_string($request['todo'])
            || !in_array($request['todo'], ['set', 'unset'], true)
            || estab_workflow_record_id($request['00_lfd'] ?? null) === null
        ) {
            return false;
        }
    }

    if (
        array_key_exists('m2_abmelden_x', $request)
        && $method !== 'POST'
    ) {
        return false;
    }

    foreach (['00_lfd', 'msglfd'] as $recordKey) {
        if (
            isset($request[$recordKey])
            && $request[$recordKey] !== ''
            && estab_workflow_record_id($request[$recordKey]) === null
        ) {
            return false;
        }
    }
    return true;
}
