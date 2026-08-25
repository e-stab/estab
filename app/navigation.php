<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Return the canonical operational areas in their user-facing order.
 *
 * Paths stay repository-relative so every rendered URL must pass through
 * estab_application_url(). The overview uses index.php as a safe helper input;
 * its root flag makes the renderer expose the canonical application root.
 *
 * @return list<array{
 *     key: string,
 *     label: string,
 *     short_label?: string,
 *     path: string,
 *     access: 'public'|'protected',
 *     root?: bool,
 *     hint?: string,
 *     duty_access?: 'LAGE_DOKUMENTATION'|'FERNMELDE_NACHWEIS'
 * }>
 */
function estab_navigation_areas(): array
{
    return [
        [
            'key' => 'overview',
            'label' => 'Übersicht',
            'short_label' => 'Übersicht',
            'path' => 'index.php',
            'access' => 'public',
            'root' => true,
        ],
        [
            'key' => 'messages',
            'label' => 'Nachrichtenvordruck',
            'short_label' => 'Nachrichten',
            'path' => '4fach/index.php',
            'access' => 'protected',
        ],
        [
            'key' => 'command-post',
            'label' => 'Führungsstellenbetrieb',
            'short_label' => 'Führungsstelle',
            'path' => '4fach/fuehrungsstelle.php',
            'access' => 'protected',
            'hint' => 'S6 · Fernmeldeplan · Melder',
        ],
        [
            'key' => 'message-overview',
            'label' => 'Meldungsübersicht',
            'short_label' => 'Meldungen',
            'path' => '4fueltg/ue_ltg.php',
            'access' => 'protected',
            'duty_access' => 'LAGE_DOKUMENTATION',
        ],
        [
            'key' => 'forms',
            'label' => 'Vordrucke',
            'short_label' => 'Vordrucke',
            'path' => '4fach/vordrucke.php',
            'access' => 'protected',
        ],
        [
            'key' => 'incident-log',
            'label' => 'Einsatztagebuch (ETB)',
            'short_label' => 'ETB',
            'path' => 'stabetb/etb.php',
            'access' => 'protected',
        ],
        [
            'key' => 'technical-log',
            'label' => 'Technisches Betriebsbuch (TBB)',
            'short_label' => 'TBB',
            'path' => 'fmtbb/tbb.php',
            'access' => 'protected',
        ],
        [
            'key' => 'tracking',
            'label' => 'Nachweisung',
            'short_label' => 'Nachweisung',
            'path' => '4fach/nachwea.php?nwalle',
            'access' => 'protected',
            'duty_access' => 'FERNMELDE_NACHWEIS',
        ],
        [
            'key' => 'bos-info',
            'label' => 'BOS-Info',
            'short_label' => 'BOS-Info',
            'path' => 'stabinfo/index.php',
            'access' => 'public',
        ],
    ];
}

/**
 * Return secondary service targets, kept separate from operational areas.
 *
 * Administration has its own technical HTTP authentication. It and the
 * handbook therefore remain directly reachable without an eStab session.
 *
 * @return list<array{
 *     key: string,
 *     label: string,
 *     short_label?: string,
 *     path: string,
 *     access: 'public',
 *     hint: string
 * }>
 */
function estab_navigation_services(): array
{
    return [
        [
            'key' => 'administration',
            'label' => 'Administration',
            'short_label' => 'Administration',
            'path' => '4fadm/admin.php',
            'access' => 'public',
            'hint' => 'Technischer Zugang',
        ],
        [
            'key' => 'handbook',
            'label' => 'Handbuch',
            'short_label' => 'Handbuch',
            'path' => 'handbuch/',
            'access' => 'public',
            'hint' => 'Bedienung und Hilfe',
        ],
    ];
}

/** Return one canonical item by key, or null for an unknown key. */
function estab_navigation_item_for_key(mixed $candidate): ?array
{
    if (!is_string($candidate)) {
        return null;
    }
    foreach (array_merge(
        estab_navigation_areas(),
        estab_navigation_services()
    ) as $item) {
        if (($item['key'] ?? null) === $candidate) {
            return estab_navigation_validated_item($item);
        }
    }
    return null;
}

/** Accept only a known protected area as a post-login destination. */
function estab_navigation_login_destination_key(mixed $candidate): ?string
{
    $item = estab_navigation_item_for_key($candidate);
    if ($item === null || $item['access'] !== 'protected') {
        return null;
    }
    return $item['key'];
}

/** Return the canonical URL for one validated navigation key. */
function estab_navigation_url_for_key(string $key): string
{
    $item = estab_navigation_item_for_key($key);
    if ($item === null) {
        throw new InvalidArgumentException('Unknown navigation key');
    }
    return estab_navigation_item_url($item);
}

/**
 * Return the canonical login target, optionally retaining one allowed area.
 *
 * A symbolic key is used instead of a caller-provided URL, preventing this
 * convenience route from becoming an open redirect.
 */
function estab_navigation_login_query(
    ?string $destinationKey = null,
    bool $submissionDiscarded = false
): string
{
    $query = ['login_flow' => 'existing'];
    if ($destinationKey !== null) {
        $destinationKey = estab_navigation_login_destination_key(
            $destinationKey
        );
        if ($destinationKey === null) {
            throw new InvalidArgumentException('Invalid login destination');
        }
        $query['next'] = $destinationKey;
    }
    if ($submissionDiscarded) {
        $query['interrupted'] = '1';
    }
    return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function estab_navigation_login_url(
    ?string $destinationKey = null,
    bool $submissionDiscarded = false
): string {
    return estab_application_url('4fach/index.php')
        . '?'
        . estab_navigation_login_query(
            $destinationKey,
            $submissionDiscarded
        );
}

/** Return the frame-safe login document used by the message controller. */
function estab_navigation_login_content_url(
    string $destinationKey,
    bool $submissionDiscarded = false
): string {
    return estab_application_url('4fach/mainindex.php')
        . '?'
        . estab_navigation_login_query(
            $destinationKey,
            $submissionDiscarded
        );
}

/**
 * Return the login document appropriate for the current browsing context.
 *
 * A protected controller rendered inside the historical mainframe must not
 * redirect to the outer two-frame workspace, otherwise that workspace would
 * be nested inside itself. Fetch Metadata identifies modern frame requests;
 * controllers that are intrinsically mainframe-local can request the same
 * safe fallback for clients that do not send this header.
 */
function estab_navigation_login_redirect_url(
    string $destinationKey,
    bool $submissionDiscarded,
    array $server = [],
    bool $preferContentDocument = false
): string {
    $effectiveServer = $server === [] ? $_SERVER : $server;
    $fetchDestination = $effectiveServer['HTTP_SEC_FETCH_DEST'] ?? '';
    $embedded = is_string($fetchDestination)
        && in_array(
            strtolower(trim($fetchDestination)),
            ['frame', 'iframe'],
            true
        );
    if ($preferContentDocument || $embedded) {
        return estab_navigation_login_content_url(
            $destinationKey,
            $submissionDiscarded
        );
    }
    return estab_navigation_login_url(
        $destinationKey,
        $submissionDiscarded
    );
}

/**
 * Require an application session for a user-facing page.
 *
 * Anonymous GET, HEAD and browser-form POST requests are sent to the explicit
 * existing-account login. A 303 deliberately discards a submitted form body;
 * it is never replayed against the login controller. Only a symbolic,
 * allow-listed area key is retained, while arbitrary request paths and query
 * parameters are deliberately discarded. Other methods keep the established
 * hard authentication boundary.
 */
function estab_navigation_require_session(
    array $session,
    string $destinationKey,
    array $server = [],
    bool $preferContentDocument = false
): void {
    $destinationKey = estab_navigation_login_destination_key($destinationKey);
    if ($destinationKey === null) {
        throw new InvalidArgumentException('Invalid protected page destination');
    }

    if (estab_auth_session_is_authenticated($session)) {
        return;
    }

    $effectiveServer = $server === [] ? $_SERVER : $server;
    $method = strtoupper((string) ($effectiveServer['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        header('Cache-Control: no-store');
        header('Vary: Cookie, Sec-Fetch-Dest');
        header(
            'Location: ' . estab_navigation_login_redirect_url(
                $destinationKey,
                $method === 'POST',
                $effectiveServer,
                $preferContentDocument
            ),
            true,
            303
        );
        exit;
    }

    estab_auth_require_session($session);
}

/**
 * Send a safe page load to the STRICT duty-function selector.
 *
 * Submitted form bodies are never replayed. The symbolic destination is kept
 * in the server-side session so selecting a valid hat can continue there.
 */
function estab_navigation_select_duty(
    array &$session,
    string $destinationKey,
    array $server = []
): never {
    $destinationKey = estab_navigation_login_destination_key($destinationKey);
    if ($destinationKey === null) {
        throw new InvalidArgumentException('Invalid duty destination');
    }
    $effectiveServer = $server === [] ? $_SERVER : $server;
    $method = strtoupper((string) ($effectiveServer['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        // An unknown/unsafe request body is deliberately discarded by the
        // 303 below; the user still lands in the normal application chrome.
        unset($session['estab_pending_navigation_key']);
    } else {
        $session['estab_pending_navigation_key'] = $destinationKey;
    }
    header('Cache-Control: no-store');
    header('Vary: Cookie');
    header(
        'Location: ' . estab_navigation_url_for_key('command-post')
            . '#meine-dienstfunktionen',
        true,
        303
    );
    exit;
}

/**
 * Whether a server-resolved identity still needs a STRICT duty selection.
 *
 * Web callers must pass the identity returned by estab_auth_session_identity()
 * (or estab_read_session_identity()). That boundary has already revalidated an
 * existing assignment against the active incident, active duty shift,
 * personally accepted assignment and exact account/function tuple. Therefore
 * a syntactically valid id here is also current authority, while a stale,
 * closed or foreign assignment has already failed the authenticated session
 * closed. LOOSE deliberately needs no selected hat.
 */
function estab_navigation_strict_duty_selection_required(
    ?array $identity
): bool {
    if ($identity === null) {
        return true;
    }
    $mode = $identity['estab_permission_mode'] ?? null;
    if ($mode === 'LOOSE') {
        return false;
    }
    if ($mode !== 'STRICT') {
        return true;
    }
    $assignmentId = filter_var(
        $identity['duty_assignment_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
    );
    return !is_int($assignmentId);
}

/**
 * Resolve the first page after a successful login for the active mode.
 *
 * STRICT must retain the originally requested protected area in server-side
 * session state and bootstrap through the duty selector. LOOSE has no duty
 * selection step and may continue directly to the validated destination.
 */
function estab_navigation_login_landing_key(
    array &$session,
    string $permissionMode,
    string $destinationKey
): string {
    $destinationKey = estab_navigation_login_destination_key($destinationKey);
    if ($destinationKey === null) {
        throw new InvalidArgumentException('Invalid login landing destination');
    }
    if ($permissionMode === 'STRICT') {
        $session['estab_pending_navigation_key'] = $destinationKey;
        return 'command-post';
    }
    if ($permissionMode === 'LOOSE') {
        unset($session['estab_pending_navigation_key']);
        return $destinationKey;
    }
    unset($session['estab_pending_navigation_key']);
    throw new InvalidArgumentException('Invalid login permission mode');
}

/** Redirect a protected direct route to the STRICT duty selector if needed. */
function estab_navigation_require_selected_duty(
    array &$session,
    ?array $identity,
    string $destinationKey,
    array $server = []
): void {
    if (!estab_navigation_strict_duty_selection_required($identity)) {
        return;
    }
    estab_navigation_select_duty(
        $session,
        $destinationKey,
        $server
    );
}

/**
 * Render the validated destination as a form field for one login tab.
 *
 * Carrying the symbolic key in each form keeps parallel tabs independent.
 * Null deliberately produces no field; every non-null value must still pass
 * the same protected-area allow-list as the initial navigation link.
 */
function estab_navigation_login_destination_field(
    mixed $destinationKey
): string {
    if ($destinationKey === null) {
        return '';
    }
    $destinationKey = estab_navigation_login_destination_key($destinationKey);
    if ($destinationKey === null) {
        throw new InvalidArgumentException('Invalid login destination field');
    }

    return '<input type="hidden" name="next" value="'
        . estab_auth_html($destinationKey) . '">';
}

/**
 * Validate a navigation definition before it reaches an HTML boundary.
 *
 * This also makes the item renderer safe for future caller-supplied catalogs:
 * absolute URLs, traversal, fragments, malformed keys, and unknown access
 * classes never become navigation links.
 *
 * @return array{
 *     key: string,
 *     label: string,
 *     short_label: string,
 *     path: string,
 *     access: 'public'|'protected',
 *     root: bool,
 *     hint: string,
 *     duty_access: ''|'LAGE_DOKUMENTATION'|'FERNMELDE_NACHWEIS'
 * }
 */
function estab_navigation_validated_item(array $item): array
{
    $key = $item['key'] ?? null;
    $label = $item['label'] ?? null;
    $shortLabel = $item['short_label'] ?? $label;
    $path = $item['path'] ?? null;
    $access = $item['access'] ?? null;
    $root = $item['root'] ?? false;
    $hint = $item['hint'] ?? '';
    $dutyAccess = $item['duty_access'] ?? '';

    if (
        !is_string($key)
        || preg_match('/\A[a-z][a-z0-9-]*\z/D', $key) !== 1
    ) {
        throw new InvalidArgumentException('Invalid navigation key');
    }
    if (!is_string($label) || trim($label) === '') {
        throw new InvalidArgumentException('Invalid navigation label');
    }
    if (!is_string($shortLabel) || trim($shortLabel) === '') {
        throw new InvalidArgumentException('Invalid short navigation label');
    }
    if (
        !is_string($path)
        || preg_match(
            "#\\A[A-Za-z0-9][A-Za-z0-9._/-]*"
                . "(?:\\?[A-Za-z0-9._~!$&'()*+,;=:@%/?-]*)?\\z#D",
            $path
        ) !== 1
        || str_contains($path, '//')
    ) {
        throw new InvalidArgumentException('Invalid navigation path');
    }
    if (
        !is_string($access)
        || !in_array($access, ['public', 'protected'], true)
    ) {
        throw new InvalidArgumentException('Invalid navigation access class');
    }
    if (!is_bool($root) || ($root && $path !== 'index.php')) {
        throw new InvalidArgumentException('Invalid navigation root target');
    }
    if (!is_string($hint)) {
        throw new InvalidArgumentException('Invalid navigation hint');
    }
    if (
        !is_string($dutyAccess)
        || !in_array(
            $dutyAccess,
            ['', 'LAGE_DOKUMENTATION', 'FERNMELDE_NACHWEIS'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid navigation duty-access class'
        );
    }

    // The shared helper is the final URL-policy boundary for every real path.
    estab_application_url($path);

    return [
        'key' => $key,
        'label' => $label,
        'short_label' => $shortLabel,
        'path' => $path,
        'access' => $access,
        'root' => $root,
        'hint' => $hint,
        'duty_access' => $dutyAccess,
    ];
}

/**
 * Pure navigation hint for the mode-specific authenticated identity.
 *
 * The endpoints repeat the authoritative capability check. Navigation only
 * suppresses links that do not match the selected STRICT duty assignment or
 * the fixed/additional LOOSE functions. Access-shift membership never grants
 * operational permissions.
 */
/**
 * Why this destination is closed to the signed-in identity right now.
 *
 * An empty string means it is open. Anything else is shown at the entry, in
 * words the person in front of the screen can act on: a destination that
 * silently disappears is indistinguishable from one that never existed, and
 * whoever saw it yesterday will look for it instead of working.
 *
 * This is a reason, not a barrier. Navigation has never been a security
 * boundary and does not become one here -- every endpoint checks its own
 * authorization, which is what keeps a typed-in address from being an open
 * door.
 */
function estab_navigation_duty_access_reason(
    array $item,
    ?array $identity
): string {
    $item = estab_navigation_validated_item($item);
    if ($item['access'] === 'public') {
        return '';
    }
    if ($identity === null) {
        return '';
    }
    if (estab_navigation_strict_duty_selection_required($identity)) {
        return $item['key'] === 'command-post'
            ? ''
            : 'Erst im Führungsstellenbetrieb eine Funktion annehmen; '
                . 'in der Betriebsart „streng“ arbeitet nur, wer im '
                . 'Dienst steht.';
    }
    if ($item['duty_access'] === '') {
        return '';
    }
    return match ($item['duty_access']) {
        'LAGE_DOKUMENTATION' => estab_auth_identity_has_function(
            $identity,
            'S2',
            'Stab'
        )
            ? ''
            : 'Dieser Bereich gehört zur Lage und Dokumentation und steht '
                . 'der Funktion S 2 offen.',
        'FERNMELDE_NACHWEIS' =>
            estab_auth_identity_has_function($identity, 'LdF', 'Fernmelder')
            || estab_auth_identity_has_function($identity, 'A/W', 'Fernmelder')
                ? ''
                : 'Dieser Bereich gehört zum Fernmeldebetrieb und steht dem '
                    . 'LdF und der Fernmeldezentrale offen.',
        default => 'Für diesen Bereich ist keine Zuständigkeit hinterlegt; '
            . 'bitte an die Administration wenden.',
    };
}

/**
 * May the signed-in identity steer this destination right now?
 *
 * The answer is derived from the reason, so the two can never disagree: an
 * entry that is closed always has something to say, and an entry that says
 * nothing is open.
 */
function estab_navigation_duty_access_allowed(
    array $item,
    ?array $identity
): bool {
    $item = estab_navigation_validated_item($item);
    if ($item['access'] !== 'public' && $identity === null) {
        return false;
    }
    return estab_navigation_duty_access_reason($item, $identity) === '';
}

/**
 * Build a real item URL exclusively through estab_application_url().
 *
 * The safe index.php input is removed only for the explicitly validated root
 * item, yielding "/" or the configured deployment root with a trailing slash.
 */
function estab_navigation_item_url(array $item): string
{
    $item = estab_navigation_validated_item($item);
    $url = estab_application_url($item['path']);
    if (!$item['root']) {
        return $url;
    }

    return substr($url, 0, -strlen($item['path']));
}

/**
 * Return a request path relative to the configured application root.
 *
 * SCRIPT_NAME and REQUEST_URI are accepted only as local absolute paths.
 * Configuration-aware prefix matching avoids treating a similarly named
 * neighbouring deployment as part of this application.
 */
function estab_navigation_relative_request_path(mixed $candidate): ?string
{
    if (
        !is_string($candidate)
        || $candidate === ''
        || !str_starts_with($candidate, '/')
        || str_starts_with($candidate, '//')
        || preg_match('/[\x00-\x1F\x7F\\\\]/D', $candidate) === 1
    ) {
        return null;
    }

    $path = parse_url($candidate, PHP_URL_PATH);
    if (!is_string($path) || !str_starts_with($path, '/')) {
        return null;
    }

    $rootPath = parse_url(
        estab_application_url('index.php'),
        PHP_URL_PATH
    );
    if (!is_string($rootPath) || !str_ends_with($rootPath, 'index.php')) {
        return null;
    }
    $rootPath = substr($rootPath, 0, -strlen('index.php'));
    $rootWithoutSlash = rtrim($rootPath, '/');

    if ($path === $rootWithoutSlash || $path === $rootPath) {
        return '';
    }
    if (!str_starts_with($path, $rootPath)) {
        return null;
    }

    return substr($path, strlen($rootPath));
}

/**
 * Resolve a repository-relative request path to one navigation key.
 *
 * The two specialist 4fach controllers deliberately precede the general
 * 4fach module match. Directory-boundary comparisons prevent partial-name
 * false positives such as /4fachish or /stabetb-old.
 */
function estab_navigation_key_for_path(string $relativePath): ?string
{
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '' || $relativePath === 'index.php') {
        return 'overview';
    }
    if ($relativePath === '4fach/vordrucke.php') {
        return 'forms';
    }
    if ($relativePath === '4fach/nachwea.php') {
        return 'tracking';
    }
    if ($relativePath === '4fach/fuehrungsstelle.php') {
        return 'command-post';
    }
    if ($relativePath === '4fach/resetpic.php') {
        return 'administration';
    }

    $modules = [
        '4fach' => 'messages',
        '4fueltg' => 'message-overview',
        'stabetb' => 'incident-log',
        'fmtbb' => 'technical-log',
        'stabinfo' => 'bos-info',
        '4fadm' => 'administration',
        'handbuch' => 'handbook',
    ];
    foreach ($modules as $directory => $key) {
        if (
            $relativePath === $directory
            || str_starts_with($relativePath, $directory . '/')
        ) {
            return $key;
        }
    }

    return null;
}

/**
 * Determine the active navigation key from server request metadata.
 *
 * A recognized SCRIPT_NAME wins because it identifies the executing PHP
 * controller. REQUEST_URI remains a fallback for servers that omit or rewrite
 * SCRIPT_NAME. The result is one key or null, never a set of active entries.
 */
function estab_navigation_active_key(array $server): ?string
{
    foreach (['SCRIPT_NAME', 'REQUEST_URI'] as $field) {
        $relativePath = estab_navigation_relative_request_path(
            $server[$field] ?? null
        );
        if ($relativePath === null) {
            continue;
        }
        $key = estab_navigation_key_for_path($relativePath);
        if ($key !== null) {
            return $key;
        }
    }

    return null;
}

/**
 * Render one escaped navigation item.
 *
 * Anonymous protected items all point at the canonical login controller.
 * Public and authenticated items expose their real, helper-built target.
 */
function estab_navigation_item_markup(
    array $item,
    bool $authenticated,
    ?string $activeKey,
    bool $shortLabel = false,
    ?array $identity = null
): string {
    $item = estab_navigation_validated_item($item);
    $locked = $item['access'] === 'protected' && !$authenticated;
    $url = $locked
        ? estab_navigation_login_url($item['key'])
        : estab_navigation_item_url($item);
    $current = $activeKey === $item['key']
        ? ' aria-current="page"'
        : '';
    $lockedAttribute = $locked
        ? ' data-estab-navigation-locked'
        : '';
    $dutyAccessAttribute = $item['duty_access'] === ''
        ? ''
        : ' data-estab-navigation-duty-access="'
            . estab_auth_html($item['duty_access']) . '"';
    $hint = $item['hint'] === ''
        ? ''
        : '<span class="estab-navigation-hint">'
            . estab_auth_html($item['hint'])
            . '</span>';
    $loginHint = $locked
        ? '<span class="estab-navigation-login-hint">'
            . 'Anmeldung erforderlich'
            . '</span>'
        : '';
    $label = $shortLabel ? $item['short_label'] : $item['label'];
    $accessibleLabel = $shortLabel && $label !== $item['label']
        ? ' aria-label="' . estab_auth_html($item['label']) . '"'
            . ' title="' . estab_auth_html($item['label']) . '"'
        : '';

    /*
     * Jeder Eintrag ist anklickbar -- auch der, den die eigene Funktion
     * gerade nicht ansteuern darf.
     *
     * Vorher stand der Grund im Menue, und der Eintrag fuehrte nirgendwohin.
     * Das hatte zwei Fehler. Der sichtbare: Ein erklaerender Satz passt nicht
     * in eine schmale Menuespalte, er zerlegte sie. Der tiefere: Ein Menue
     * ist zum Hingehen da, nicht zum Erklaeren. Wer wissen will, warum ein
     * Bereich ihm verschlossen ist, klickt ihn an und liest es dort -- an
     * der Stelle, an der es ihn betrifft und wo Platz fuer einen ganzen Satz
     * ist.
     *
     * Die Sicherheitslage bleibt unveraendert. Die Navigation war nie eine
     * Sicherheitsgrenze; jeder Endpunkt prueft Anmeldung, angetretenen
     * Dienst und Bereichsberechtigung selbst und weist ohne sie ab.
     */
    return '<li class="estab-navigation-item"'
        . ' data-estab-navigation-item'
        . ' data-estab-navigation-key="' . estab_auth_html($item['key']) . '"'
        . ' data-estab-navigation-access="'
        . estab_auth_html($item['access']) . '"'
        . $lockedAttribute
        . $dutyAccessAttribute . '>'
        . '<a class="estab-navigation-link" href="'
        . estab_auth_html($url) . '" target="_top"'
        . ' data-estab-nav-key="' . estab_auth_html($item['key']) . '"'
        . $accessibleLabel
        . $current . '>'
        . '<span class="estab-navigation-label">'
        . estab_auth_html($label) . '</span>'
        . $hint . $loginHint
        . '</a>'
        . '</li>';
}

/** Render an escaped list while enforcing unique keys in one group. */
function estab_navigation_group_markup(
    array $items,
    bool $authenticated,
    ?string $activeKey,
    string $group,
    bool $shortLabels = false,
    ?array $identity = null
): string {
    if (preg_match('/\A[a-z][a-z0-9-]*\z/D', $group) !== 1) {
        throw new InvalidArgumentException('Invalid navigation group');
    }

    $markup = '';
    $keys = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Invalid navigation item');
        }
        $validated = estab_navigation_validated_item($item);
        if (isset($keys[$validated['key']])) {
            throw new InvalidArgumentException('Duplicate navigation key');
        }
        $keys[$validated['key']] = true;
        $markup .= estab_navigation_item_markup(
            $validated,
            $authenticated,
            $activeKey,
            $shortLabels,
            $identity
        );
    }

    return '<ul class="estab-navigation-list"'
        . ' data-estab-navigation-group="' . estab_auth_html($group) . '">'
        . $markup . '</ul>';
}

/**
 * Render the shared application navigation.
 *
 * Full mode emits the two navigation groups directly. Compact mode wraps the
 * same links in a native details/summary control suitable for narrow frames.
 * Every link navigates the top-level browsing context; no new tabs are opened.
 */
function estab_navigation_markup(
    bool $authenticated,
    array $server = [],
    bool $compact = false,
    bool $sidebar = false,
    ?array $identity = null
): string {
    if ($sidebar && !$compact) {
        throw new InvalidArgumentException(
            'Sidebar navigation requires compact session chrome'
        );
    }
    $activeKey = estab_navigation_active_key($server);
    $areas = estab_navigation_group_markup(
        estab_navigation_areas(),
        $authenticated,
        $activeKey,
        'areas',
        $sidebar,
        $identity
    );
    $services = '<div class="estab-navigation-services">'
        . '<span class="estab-navigation-services-label">Service</span>'
        . estab_navigation_group_markup(
            estab_navigation_services(),
            $authenticated,
            $activeKey,
            'services',
            $sidebar,
            $identity
        )
        . '</div>';
    $content = '<div class="estab-navigation-content">'
        . $areas . $services . '</div>';
    $mode = $sidebar ? 'sidebar' : ($compact ? 'compact' : 'full');

    if ($sidebar) {
        $content = '<div class="estab-navigation-sidebar-heading">'
            . '<h2>Bereiche</h2>'
            . '<p>Arbeitsbereich wechseln</p>'
            . '</div>' . $content;
    } elseif ($compact) {
        $content = '<details class="estab-navigation-disclosure">'
            . '<summary>Bereich wechseln</summary>'
            . $content
            . '</details>';
    }

    return '<nav class="estab-navigation estab-navigation-' . $mode . '"'
        . ' data-estab-navigation'
        . ' data-estab-navigation-mode="' . $mode . '"'
        . ' aria-label="Bereichsnavigation">'
        . $content
        . '</nav>';
}
