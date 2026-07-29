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
 *     hint?: string
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
            'key' => 'message-overview',
            'label' => 'Meldungsübersicht',
            'short_label' => 'Meldungen',
            'path' => '4fueltg/ue_ltg.php',
            'access' => 'protected',
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
            'label' => 'Technisches Betriebsbuch (TTB)',
            'short_label' => 'TTB',
            'path' => 'fmtbb/tbb.php',
            'access' => 'protected',
        ],
        [
            'key' => 'tracking',
            'label' => 'Nachweisung',
            'short_label' => 'Nachweisung',
            'path' => '4fach/nachwea.php?nwalle',
            'access' => 'protected',
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
            'path' => 'doku/Handbuch_eStab.pdf',
            'access' => 'public',
            'hint' => 'Dokumentation',
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
function estab_navigation_login_url(?string $destinationKey = null): string
{
    $url = estab_application_url('4fach/index.php');
    if ($destinationKey === null) {
        return $url;
    }
    $destinationKey = estab_navigation_login_destination_key($destinationKey);
    if ($destinationKey === null) {
        throw new InvalidArgumentException('Invalid login destination');
    }
    return $url . '?next=' . rawurlencode($destinationKey);
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
 *     hint: string
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
    ];
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
        'doku' => 'handbook',
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
    bool $shortLabel = false
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

    return '<li class="estab-navigation-item"'
        . ' data-estab-navigation-item'
        . ' data-estab-navigation-key="' . estab_auth_html($item['key']) . '"'
        . ' data-estab-navigation-access="'
        . estab_auth_html($item['access']) . '"'
        . $lockedAttribute . '>'
        . '<a class="estab-navigation-link" href="'
        . estab_auth_html($url) . '" target="_top"'
        . ' data-estab-nav-key="' . estab_auth_html($item['key']) . '"'
        . $accessibleLabel
        . $current . '>'
        . '<span class="estab-navigation-label">'
        . estab_auth_html($label) . '</span>'
        . $hint . $loginHint
        . '</a></li>';
}

/** Render an escaped list while enforcing unique keys in one group. */
function estab_navigation_group_markup(
    array $items,
    bool $authenticated,
    ?string $activeKey,
    string $group,
    bool $shortLabels = false
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
            $shortLabels
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
    bool $sidebar = false
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
        $sidebar
    );
    $services = '<div class="estab-navigation-services">'
        . '<span class="estab-navigation-services-label">Service</span>'
        . estab_navigation_group_markup(
            estab_navigation_services(),
            $authenticated,
            $activeKey,
            'services',
            $sidebar
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
