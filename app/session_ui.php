<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

/** Return the configured browser root used by shared session controls. */
function estab_session_ui_root(): string
{
    return estab_public_root() . estab_base_path();
}

/** Return the shared stylesheet element for standalone application pages. */
function estab_session_ui_stylesheet(): string
{
    return '<link rel="stylesheet" href="'
        . estab_auth_html(estab_session_ui_root() . 'estab-ui.css')
        . '">';
}

/**
 * Render one validated application session and its protected logout control.
 *
 * The session shape is validated by the same function that guards protected
 * endpoints. Every displayed value and attribute is escaped at this boundary.
 */
function estab_session_ui_markup(
    array $session,
    string $csrfToken,
    bool $compact = false
): string {
    $identity = estab_auth_session_identity($session);
    if ($identity === null) {
        return '';
    }
    if (preg_match('/\A[a-f0-9]{64}\z/D', $csrfToken) !== 1) {
        throw new InvalidArgumentException('Invalid session UI CSRF token');
    }

    $barClass = $compact
        ? 'estab-session-bar estab-session-bar-compact'
        : 'estab-session-bar';
    $logoutUrl = estab_session_ui_root() . '4fach/logout.php';
    $name = estab_auth_html($identity['benutzer']);
    $code = estab_auth_html($identity['kuerzel']);
    $function = estab_auth_html($identity['funktion']);
    $role = estab_auth_html($identity['rolle']);

    return '<aside class="' . $barClass . '" data-estab-session-bar'
        . ' aria-label="Aktuelle Anmeldung">'
        . '<div class="estab-session-identity">'
        . '<span class="estab-session-prefix">Angemeldet als</span>'
        . '<strong data-estab-user-name="' . $name . '">' . $name . '</strong>'
        . '<span class="estab-session-detail"'
        . ' data-estab-user-code="' . $code . '"'
        . ' data-estab-user-function="' . $function . '"'
        . ' data-estab-user-role="' . $role . '">'
        . 'Kürzel ' . $code
        . ' · Funktion ' . $function
        . ' · Rolle ' . $role
        . '</span>'
        . '</div>'
        . '<form class="estab-session-logout" data-estab-logout-form'
        . ' method="post" action="' . estab_auth_html($logoutUrl)
        . '" target="_top">'
        . '<input type="hidden" name="csrf_token" value="'
        . estab_auth_html($csrfToken) . '">'
        . '<input type="hidden" name="logout_action" value="logout">'
        . '<button class="estab-button estab-button-logout"'
        . ' type="submit">Abmelden</button>'
        . '</form>'
        . '</aside>';
}

/** Return the session bar for a valid current login, or an empty string. */
function estab_session_ui_current_markup(array $session, bool $compact = false): string
{
    if (
        session_status() !== PHP_SESSION_ACTIVE
        || estab_auth_session_identity($session) === null
    ) {
        return '';
    }

    return estab_session_ui_markup($session, estab_csrf_token(), $compact);
}

/** Detect the actual shared element without colliding with escaped user text. */
function estab_session_ui_document_has_bar(string $html): bool
{
    return preg_match(
        '/<aside\b[^>]*\bdata-estab-session-bar\b[^>]*>/i',
        $html
    ) === 1;
}

/**
 * Decide whether the selected controller still produces an HTML response.
 *
 * Legacy HTML often has no explicit Content-Type, so an absent header remains
 * eligible. Explicit non-HTML responses and redirects must stay untouched.
 */
function estab_session_ui_response_is_html(array $headers, int $status): bool
{
    if (
        $status < 200
        || ($status >= 300 && $status < 400)
        || in_array($status, [204, 205], true)
    ) {
        return false;
    }

    foreach ($headers as $header) {
        if (!is_string($header) || stripos($header, 'Content-Type:') !== 0) {
            continue;
        }
        $contentType = trim(substr($header, strlen('Content-Type:')));
        return preg_match(
            '~^(?:text/html|application/xhtml\+xml)(?:\s*;|$)~i',
            $contentType
        ) === 1;
    }

    return true;
}

/** The frameset supplies one persistent bar, so its helper frames stay lean. */
function estab_session_ui_is_embedded_frame(array $query): bool
{
    return ($query['embedded'] ?? null) === '1';
}

/**
 * Insert one bar into a complete document, or wrap a legacy HTML fragment.
 *
 * The last opening body tag is intentional: two historical form renderers
 * emit a harmless duplicate body tag immediately before their real content.
 */
function estab_session_ui_inject_document(string $html, string $markup): string
{
    if ($markup === '' || estab_session_ui_document_has_bar($html)) {
        return $html;
    }

    $bodyCount = preg_match_all(
        '/<body\b[^>]*>/i',
        $html,
        $bodyMatches,
        PREG_OFFSET_CAPTURE
    );
    if (is_int($bodyCount) && $bodyCount > 0) {
        $lastBody = $bodyMatches[0][array_key_last($bodyMatches[0])];
        $bodyEnd = $lastBody[1] + strlen($lastBody[0]);
        $html = substr($html, 0, $bodyEnd) . $markup . substr($html, $bodyEnd);

        if (!str_contains($html, 'estab-ui.css')) {
            $headEnd = strripos($html, '</head>');
            if ($headEnd !== false) {
                $html = substr($html, 0, $headEnd)
                    . estab_session_ui_stylesheet()
                    . substr($html, $headEnd);
            }
        }
        return $html;
    }

    return '<!doctype html><html lang="de"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . estab_session_ui_stylesheet()
        . '</head><body>' . $markup . $html . '</body></html>';
}

/**
 * Enable the shared UI for one explicitly selected HTML controller.
 *
 * Registration is deliberately not global: downloads, image endpoints,
 * health checks and plain-text errors remain byte-for-byte untouched.
 */
function estab_session_ui_start(array $session, bool $compact = false): void
{
    static $started = false;
    if ($started || PHP_SAPI === 'cli') {
        return;
    }
    $started = true;
    if (
        estab_auth_session_identity($session) !== null
        && !headers_sent()
    ) {
        header('Cache-Control: private, no-store, max-age=0');
    }

    ob_start(static function (string $html) use ($compact): string {
        $status = http_response_code();
        $status = is_int($status) ? $status : 200;
        if (!estab_session_ui_response_is_html(headers_list(), $status)) {
            return $html;
        }

        return estab_session_ui_inject_document(
            $html,
            estab_session_ui_current_markup($_SESSION, $compact)
        );
    });
}
