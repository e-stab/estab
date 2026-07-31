<?php

/**
 * Request and role boundary for the historical main controller.
 *
 * The legacy controller performs many actions in one file. Keeping the
 * admission decision pure and central makes it possible to prove that no
 * request reaches those branches before a valid application login.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/navigation.php';

/** Return true only for the exact anonymous requests used by the login UI. */
function estab_workflow_public_login_request(array $server, array $get, array $post): bool
{
    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        if ($post !== []) {
            return false;
        }
        if ($get === []) {
            return true;
        }
        foreach (array_keys($get) as $key) {
            if (
                !is_string($key)
                || !in_array(
                    $key,
                    ['login_flow', 'next', 'interrupted'],
                    true
                )
            ) {
                return false;
            }
        }
        if (
            array_key_exists('login_flow', $get)
            && estab_auth_login_flow($get) === null
        ) {
            return false;
        }
        if (
            array_key_exists('next', $get)
            && estab_navigation_login_destination_key($get['next']) === null
        ) {
            return false;
        }
        if (
            array_key_exists('interrupted', $get)
            && $get['interrupted'] !== '1'
        ) {
            return false;
        }
        return true;
    }
    if ($method !== 'POST' || $get !== [] || $post === []) {
        return false;
    }

    $allowedKeys = [
        'login', 'login_x', 'login_y',
        'login_identity', 'login_flow',
        'benutzer', 'kuerzel', 'funktion',
        'kennwort1', 'kennwort2', '2teskennwort',
        'absenden_x', 'absenden_y',
        'csrf_token', 'next',
    ];
    foreach (array_keys($post) as $key) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
            return false;
        }
    }
    if (
        isset($post['csrf_token'])
        && (
            !is_string($post['csrf_token'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $post['csrf_token']) !== 1
        )
    ) {
        return false;
    }
    $request = $post;
    unset($request['csrf_token']);
    if (array_key_exists('next', $request)) {
        if (
            estab_navigation_login_destination_key($request['next']) === null
        ) {
            return false;
        }
        unset($request['next']);
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

    if (array_key_exists('login', $request)) {
        $extraKeys = array_diff(array_keys($request), ['login', 'login_x', 'login_y']);
        $hasCoordinates = isset($request['login_x']) || isset($request['login_y']);
        return is_string($request['login'])
            && $request['login'] === 'Anmelden'
            && $extraKeys === []
            && (!$hasCoordinates || $coordinatePairValid($request));
    }

    if (
        array_keys($request) === ['login_x', 'login_y']
        || array_keys($request) === ['login_y', 'login_x']
    ) {
        return $coordinatePairValid($request);
    }

    if (array_keys($request) === ['login_flow']) {
        return estab_auth_login_flow($request) !== null;
    }

    if (array_key_exists('login_identity', $request)) {
        return is_string($request['login_identity'])
            && $request['login_identity'] !== ''
            && array_keys($request) === ['login_identity'];
    }

    foreach (['benutzer', 'kuerzel', 'funktion', 'kennwort1'] as $requiredKey) {
        if (!isset($request[$requiredKey]) || !is_string($request[$requiredKey])) {
            return false;
        }
    }
    $loginFlow = estab_auth_login_flow($request);
    if ($loginFlow === null) {
        return false;
    }
    if (isset($request['2teskennwort'])) {
        if (!is_string($request['2teskennwort']) || !in_array($request['2teskennwort'], ['Yes', 'No'], true)) {
            return false;
        }
        if ($request['2teskennwort'] === 'Yes' && !isset($request['kennwort2'])) {
            return false;
        }
    }
    if ($loginFlow === 'new') {
        if (
            !isset($request['kennwort2'])
            || !is_string($request['kennwort2'])
            || (
                isset($request['2teskennwort'])
                && $request['2teskennwort'] !== 'Yes'
            )
        ) {
            return false;
        }
    } elseif (
        isset($request['kennwort2'])
        || (
            isset($request['2teskennwort'])
            && $request['2teskennwort'] !== 'No'
        )
    ) {
        return false;
    }
    return !isset($request['login'], $request['login_identity']);
}

/**
 * Recognize a same-site operational form whose application session expired.
 *
 * Login attempts, cross-site requests and empty posts remain on the strict
 * denial path. The caller may only answer a matching request with a 303, so
 * none of the submitted operational values are replayed or processed.
 */
function estab_workflow_anonymous_operational_post(
    array $server,
    array $get,
    array $post
): bool {
    if (
        strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
        || $get !== []
        || $post === []
        || !estab_workflow_login_metadata_same_site($server)
    ) {
        return false;
    }
    foreach ([
        'login',
        'login_x',
        'login_y',
        'login_identity',
        'login_flow',
        'benutzer',
        'kuerzel',
        'funktion',
        'kennwort1',
        'kennwort2',
        '2teskennwort',
    ] as $loginKey) {
        if (array_key_exists($loginKey, $post)) {
            return false;
        }
    }
    return true;
}

/**
 * Recognize an operational page link after the application session expired.
 *
 * Login metadata is deliberately excluded: malformed login destinations or
 * interruption flags stay on the strict denial path. Operational query values
 * are never copied to the login URL and therefore cannot become a redirect
 * target or be processed without a valid session.
 */
function estab_workflow_anonymous_operational_get(
    array $server,
    array $get,
    array $post
): bool {
    if (
        strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) !== 'GET'
        || $get === []
        || $post !== []
    ) {
        return false;
    }
    foreach ([
        'login',
        'login_x',
        'login_y',
        'login_identity',
        'login_flow',
        'next',
        'interrupted',
        'benutzer',
        'kuerzel',
        'funktion',
        'kennwort1',
        'kennwort2',
        '2teskennwort',
        'absenden_x',
        'absenden_y',
        'anmelden',
        'csrf_token',
    ] as $loginKey) {
        if (array_key_exists($loginKey, $get)) {
            return false;
        }
    }
    return true;
}

/** Return whether this POST can authenticate or register an account. */
function estab_workflow_login_credentials_present(array $post): bool
{
    foreach (['benutzer', 'kuerzel', 'funktion', 'kennwort1'] as $requiredKey) {
        if (!isset($post[$requiredKey]) || !is_string($post[$requiredKey])) {
            return false;
        }
    }
    return true;
}

/** Compare browser request metadata with the current request authority. */
function estab_workflow_login_metadata_same_site(array $server): bool
{
    $fetchSite = $server['HTTP_SEC_FETCH_SITE'] ?? '';
    if (
        $fetchSite !== ''
        && (
            !is_string($fetchSite)
            || !in_array(strtolower($fetchSite), ['same-origin', 'same-site', 'none'], true)
        )
    ) {
        return false;
    }

    $requestAuthority = $server['HTTP_HOST'] ?? '';
    if (!is_string($requestAuthority)) {
        return false;
    }
    $requestUrl = parse_url('http://' . trim($requestAuthority));
    $requestHost = is_array($requestUrl) && isset($requestUrl['host'])
        ? strtolower((string) $requestUrl['host'])
        : '';
    $requestPort = is_array($requestUrl) && isset($requestUrl['port'])
        ? (int) $requestUrl['port']
        : null;

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $metadataKey) {
        if (!array_key_exists($metadataKey, $server)) {
            continue;
        }
        $metadata = $server[$metadataKey];
        if (!is_string($metadata) || trim($metadata) === '' || $requestHost === '') {
            return false;
        }
        $metadataUrl = parse_url($metadata);
        if (!is_array($metadataUrl) || !isset($metadataUrl['host'])) {
            return false;
        }
        $metadataHost = strtolower((string) $metadataUrl['host']);
        $metadataPort = isset($metadataUrl['port']) ? (int) $metadataUrl['port'] : null;
        if (
            !hash_equals($requestHost, $metadataHost)
            || (
                $requestPort !== null
                && $metadataPort !== null
                && $requestPort !== $metadataPort
            )
        ) {
            return false;
        }
    }
    return true;
}

/**
 * Keep tokenless legacy clients behind an explicit, default-off boundary.
 *
 * Browser requests that identify themselves as cross-site are rejected even
 * after an operator has enabled this compatibility mode.
 */
function estab_workflow_legacy_login_without_csrf_allowed(array $server, array $post): bool
{
    return estab_env_bool('ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF', false)
        && estab_workflow_login_credentials_present($post)
        && !array_key_exists('login_flow', $post)
        && !array_key_exists('csrf_token', $post)
        && estab_workflow_login_metadata_same_site($server);
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

/** Leiter der Fernmeldebetriebsstelle: Rufnamen und Transportwege disponieren. */
function estab_workflow_is_telecommunications_lead(array $identity): bool
{
    return ($identity['funktion'] ?? '') === 'LdF'
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
        'zurueckweisen_x', 'zurueckweisen_y',
        'transport_nicht_moeglich_x', 'transport_nicht_moeglich_y',
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
        'ldf_nachrichten_x', 'ldf_nachrichten_y',
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

/**
 * Bind browser matrix coordinates to the exact server matrix they displayed.
 *
 * Function names remain server-owned. The revision is not an authorisation
 * token; it is a stale-form guard that prevents a coordinate from silently
 * resolving to a different function after an administrative matrix change.
 */
function estab_workflow_recipient_matrix_revision(
    array $matrix,
    string $redCopyFunction = ''
): string {
    $canonical = ['red_copy' => $redCopyFunction, 'cells' => []];
    for ($row = 1; $row <= 5; $row++) {
        for ($column = 1; $column <= 4; $column++) {
            $cell = is_array($matrix[$row][$column] ?? null)
                ? $matrix[$row][$column]
                : [];
            $canonical['cells'][] = [
                'row' => $row,
                'column' => $column,
                'function' => (string) ($cell['fkt'] ?? ''),
                'role' => (string) ($cell['rolle'] ?? ''),
                'type' => (string) ($cell['typ'] ?? ''),
                'mode' => (string) ($cell['mode'] ?? ''),
                'automatic' => (string) ($cell['auto'] ?? ''),
            ];
        }
    }
    $encoded = json_encode(
        $canonical,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    return hash('sha256', $encoded);
}

/** Reject a missing, malformed or stale recipient-matrix revision. */
function estab_workflow_require_recipient_matrix_revision(
    array $request,
    array $matrix,
    string $redCopyFunction = ''
): void {
    $candidate = $request['recipient_matrix_revision'] ?? null;
    $expected = estab_workflow_recipient_matrix_revision(
        $matrix,
        $redCopyFunction
    );
    if (
        !is_string($candidate)
        || preg_match('/\A[a-f0-9]{64}\z/D', $candidate) !== 1
        || !hash_equals($expected, $candidate)
    ) {
        throw new InvalidArgumentException(
            'Die Empfängermatrix wurde geändert. Öffnen Sie den Vordruck neu.'
        );
    }
}

/**
 * Parse the only recipient controls emitted by the message form.
 *
 * The browser submits matrix coordinates, never function names or copy-colour
 * suffixes. The save boundary resolves every accepted coordinate against the
 * current server-side matrix.
 *
 * @return array{blue:list<array{0:int,1:int}>,green:?array{0:int,1:int}}
 */
function estab_workflow_distribution_selection(array $request): array
{
    $blue = [];
    $green = null;

    foreach ($request as $field => $value) {
        if (!is_string($field) || !str_starts_with($field, '16_')) {
            continue;
        }

        if ($field === '16_gncopy') {
            if ($value === '') {
                continue;
            }
            if (
                !is_string($value)
                || preg_match('/\A16_([1-5])([1-4])_gn\z/D', $value, $parts) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Ungültige grüne Empfängerkopie'
                );
            }
            $green = [(int) $parts[1], (int) $parts[2]];
            continue;
        }

        if (
            $field === '16_empf'
            || preg_match('/\A16_empf_sonst_[1-5][1-4]\z/D', $field) === 1
        ) {
            if ($value === '') {
                // Historical forms may submit an empty presentation field.
                // It carries no authority and is intentionally ignored.
                continue;
            }
            throw new InvalidArgumentException(
                'Empfängerfunktionen dürfen nicht übermittelt werden'
            );
        }

        if (
            preg_match('/\A16_([1-5])([1-4])\z/D', $field, $parts) !== 1
            || !is_string($value)
            || !hash_equals($field . '_bl', $value)
        ) {
            // In particular, 16_empf and 16_empf_sonst_* are never trusted
            // browser sources for a recipient function.
            throw new InvalidArgumentException(
                'Ungültige Empfängerverteilung'
            );
        }
        $blue[] = [(int) $parts[1], (int) $parts[2]];
    }

    return ['blue' => $blue, 'green' => $green];
}

/**
 * Resolve validated browser coordinates to canonical matrix recipient tokens.
 */
function estab_workflow_distribution_tokens(
    array $request,
    array $matrix,
    array $requiredTokens = []
): string {
    $selection = estab_workflow_distribution_selection($request);
    $tokens = [];
    foreach ($requiredTokens as $requiredToken) {
        if (
            !is_string($requiredToken)
            || preg_match(
                '/\A[A-Za-z0-9_]{1,6}_(?:bl|gn|rt)\z/D',
                $requiredToken
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Ungültiger vorgeschriebener Empfänger'
            );
        }
        $tokens[$requiredToken] = true;
    }
    $append = static function (
        array $coordinate,
        string $colour
    ) use ($matrix, &$tokens): void {
        [$row, $column] = $coordinate;
        $function = $matrix[$row][$column]['fkt'] ?? null;
        if (
            !is_string($function)
            || preg_match('/\A[A-Za-z0-9_]{1,6}\z/D', $function) !== 1
        ) {
            throw new InvalidArgumentException(
                'Empfängerposition ist nicht belegt'
            );
        }
        $tokens[$function . '_' . $colour] = true;
    };

    $blueCoordinates = [];
    foreach ($selection['blue'] as [$row, $column]) {
        $blueCoordinates[$row . ':' . $column] = true;
    }
    for ($row = 1; $row <= 5; $row++) {
        for ($column = 1; $column <= 4; $column++) {
            $coordinate = [$row, $column];
            if (isset($blueCoordinates[$row . ':' . $column])) {
                $append($coordinate, 'bl');
            }
            if (
                is_array($selection['green'])
                && $selection['green'] === $coordinate
            ) {
                $append($coordinate, 'gn');
            }
        }
    }

    return $tokens === [] ? '' : implode(',', array_keys($tokens)) . ',';
}

/** Return the object-level permission required by this request, if any. */
function estab_workflow_message_operation(array $request): ?string
{
    if (array_key_exists('reset_record', $request)) {
        return 'message-operator-reset';
    }
    if (isset($request['action'])) {
        return 'staff-state';
    }

    $task = (string) ($request['task'] ?? '');
    if ($task !== '') {
        return match ($task) {
            'Stab_lesen' => 'staff-read',
            'Stab_korrigieren' => 'staff-correction',
            'Stab_sichten' => 'viewer-review',
            'FM-Ausgang' => 'telecommunications-save',
            'LdF-Eingang' => 'telecommunications-lead-incoming-save',
            'LdF-Ausgang' => 'telecommunications-lead-outgoing-save',
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
    if (($request['ldf'] ?? '') === 'meldung') {
        return 'telecommunications-lead-edit';
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
    foreach ([
        'login', 'login_x', 'login_y', 'login_identity', 'login_flow',
        'benutzer', 'kuerzel', 'funktion', 'kennwort1', 'kennwort2',
        '2teskennwort', 'next',
    ] as $loginKey) {
        if (array_key_exists($loginKey, $request)) {
            // Account selection and authentication always start from a fresh
            // anonymous session. An authenticated request must log off first.
            return false;
        }
    }

    $isTelecommunications = estab_workflow_is_telecommunications($identity);
    $isTelecommunicationsLead =
        estab_workflow_is_telecommunications_lead($identity);
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
            || (!$isTelecommunications && !$isTelecommunicationsLead)
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
            'Stab_schreiben', 'Stab_korrigieren',
            'Stab_gesprnoti', 'Stab_lesen' => $isStaffWriter,
            'Stab_sichten' => $isViewer,
            'LdF-Eingang', 'LdF-Ausgang' => $isTelecommunicationsLead,
            'FM-Ausgang',
            'FM-Eingang', 'FM-Eingang_Anhang' => $isTelecommunications,
            default => false,
        };
        if (!$allowed) {
            return false;
        }
        if (in_array($task, ['Stab_sichten', 'Stab_gesprnoti'], true)) {
            try {
                estab_workflow_distribution_selection($request);
            } catch (InvalidArgumentException) {
                return false;
            }
            if (
                $task === 'Stab_gesprnoti'
                && array_key_exists('16_gncopy', $request)
                && $request['16_gncopy'] !== ''
            ) {
                // The single green conversation-note copy is bound to the
                // authenticated author's function by the save handler.
                return false;
            }
        }
        if (
            in_array(
                $task,
                [
                    'FM-Eingang', 'FM-Eingang_Anhang',
                ],
                true
            )
            && (
                array_key_exists('13_abseinheit', $request)
                && (
                    !is_string($request['13_abseinheit'])
                    || trim($request['13_abseinheit']) !== ''
                )
            )
        ) {
            // A/W records only the received callsign. LdF is the sole actor
            // that may translate it into the sender field.
            return false;
        }
        if (
            in_array($task, ['FM-Eingang', 'FM-Eingang_Anhang'], true)
        ) {
            foreach (array_keys($request) as $field) {
                if (is_string($field) && str_starts_with($field, '16_')) {
                    // Distribution is a result of the qualified Si review.
                    // A/W cannot nominate recipients while recording an
                    // incoming transmission, even through hidden legacy
                    // controls or an attachment roundtrip.
                    return false;
                }
            }
        }

        // Local operator marks are attributes of the authenticated session,
        // never browser-selected form values. Missing values are accepted
        // because the save handler supplies them authoritatively; a supplied
        // value must match exactly.
        $identityBoundFields = match ($task) {
            'FM-Eingang', 'FM-Eingang_Anhang' => [
                '01_zeichen' => (string) ($identity['kuerzel'] ?? ''),
            ],
            'Stab_schreiben', 'Stab_korrigieren' => [
                '14_zeichen' => (string) ($identity['kuerzel'] ?? ''),
                '14_funktion' => (string) ($identity['funktion'] ?? ''),
            ],
            'Stab_gesprnoti' => [
                '01_zeichen' => (string) ($identity['kuerzel'] ?? ''),
                '14_zeichen' => (string) ($identity['kuerzel'] ?? ''),
                '14_funktion' => (string) ($identity['funktion'] ?? ''),
                // A conversation note is a self-recorded staff object, not a
                // fictitious Si/LdF review. The field remains empty.
                '15_quitzeichen' => '',
                '15_quitdatum' => '',
            ],
            'Stab_sichten' => [
                '15_quitzeichen' => (string) ($identity['kuerzel'] ?? ''),
            ],
            'LdF-Eingang', 'LdF-Ausgang' => [
                '02_zeichen' => (string) ($identity['kuerzel'] ?? ''),
            ],
            'FM-Ausgang' => [
                '03_zeichen' => (string) ($identity['kuerzel'] ?? ''),
            ],
            default => [],
        };
        foreach ($identityBoundFields as $field => $expected) {
            if (
                array_key_exists($field, $request)
                && (
                    !is_string($request[$field])
                    || !hash_equals($expected, $request[$field])
                )
            ) {
                return false;
            }
        }
        if (
            $task === 'Stab_sichten'
            && array_key_exists('15_quitdatum', $request)
        ) {
            // The completion time is evidence generated by the server at the
            // successful transition, never a browser-selected timestamp.
            return false;
        }
        if (
            $task === 'Stab_korrigieren'
            && array_key_exists('17_vermerke', $request)
        ) {
            // Field 17 contains the Sichter's return reason. The author sees
            // it read-only and may not replace the immutable evidence text.
            return false;
        }
        if (
            $task === 'Stab_korrigieren'
            && array_key_exists('11_gesprnotiz', $request)
        ) {
            // A returned outgoing record may be corrected and resubmitted, but
            // never converted into a new conversation-note object. The field
            // is not submitted by the correction form, so any occurrence is
            // an overpost and fails closed.
            return false;
        }

        if (
            (isset($request['zurueckweisen_x'])
                || isset($request['zurueckweisen_y']))
            && $task !== 'Stab_sichten'
        ) {
            return false;
        }
        if (
            (isset($request['transport_nicht_moeglich_x'])
                || isset($request['transport_nicht_moeglich_y']))
            && $task !== 'FM-Ausgang'
        ) {
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
    if (isset($request['ldf']) && (string) $request['ldf'] !== '') {
        if (
            !is_string($request['ldf'])
            || $request['ldf'] !== 'meldung'
            || !$isTelecommunicationsLead
            || $method !== 'POST'
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
        'ldf_nachrichten_x' => $isTelecommunicationsLead,
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
