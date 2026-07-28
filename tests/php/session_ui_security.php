<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/session_ui.php';
require_once __DIR__ . '/../../app/logout.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$token = str_repeat('a', 64);
$identity = [
    'vStab_benutzer' => 'Müller & "Ada"',
    'vStab_kuerzel' => 'ada001',
    'vStab_funktion' => 'S1',
    'vStab_rolle' => 'Stab & Team',
];

$assert(estab_session_ui_markup([], $token) === '', 'anonymous session received session chrome');
$assert(
    estab_session_ui_markup([
        'vStab_benutzer' => '<script>',
        'vStab_kuerzel' => 'ada001',
        'vStab_funktion' => 'S1',
        'vStab_rolle' => 'Stab',
    ], $token) === '',
    'invalid session identity received session chrome'
);

$markup = estab_session_ui_markup($identity, $token);
$assert(substr_count($markup, 'data-estab-session-bar') === 1, 'session bar marker missing');
$assert(substr_count($markup, 'data-estab-logout-form') === 1, 'logout form marker missing');
$assert(str_contains($markup, 'Angemeldet als'), 'visible identity prefix missing');
$assert(
    str_contains($markup, 'Müller &amp; &quot;Ada&quot;')
        && !str_contains($markup, 'Müller & "Ada"'),
    'session name was not safely escaped'
);
$assert(
    str_contains($markup, 'data-estab-user-code="ada001"')
        && str_contains($markup, 'data-estab-user-function="S1"')
        && str_contains($markup, 'data-estab-user-role="Stab &amp; Team"'),
    'code, function, or role missing from session bar'
);
$assert(
    str_contains($markup, 'method="post"')
        && str_contains($markup, 'target="_top"')
        && str_contains($markup, '4fach/logout.php')
        && str_contains($markup, 'name="logout_action" value="logout"')
        && str_contains($markup, '>Abmelden</button>'),
    'logout form contract incomplete'
);
$assert(
    str_contains($markup, 'name="csrf_token" value="' . $token . '"'),
    'logout form CSRF token missing'
);

$compact = estab_session_ui_markup($identity, $token, true);
$assert(
    str_contains($compact, 'estab-session-bar-compact'),
    'compact frame presentation missing'
);

$originalPublicUrl = getenv('ESTAB_PUBLIC_URL');
$originalBasePath = getenv('ESTAB_BASE_PATH');
putenv('ESTAB_PUBLIC_URL=https://example.invalid/gateway');
putenv('ESTAB_BASE_PATH=dispatch/site');
$scopedMarkup = estab_session_ui_markup($identity, $token);
$scopedStylesheet = estab_session_ui_stylesheet();
$assert(
    str_contains(
        $scopedMarkup,
        'action="https://example.invalid/gateway/dispatch/site/4fach/logout.php"'
    ),
    'logout action did not preserve public URL and deployment base path'
);
$assert(
    str_contains(
        $scopedStylesheet,
        'href="https://example.invalid/gateway/dispatch/site/estab-ui.css"'
    ),
    'session stylesheet did not preserve public URL and deployment base path'
);
if ($originalPublicUrl === false) {
    putenv('ESTAB_PUBLIC_URL');
} else {
    putenv('ESTAB_PUBLIC_URL=' . $originalPublicUrl);
}
if ($originalBasePath === false) {
    putenv('ESTAB_BASE_PATH');
} else {
    putenv('ESTAB_BASE_PATH=' . $originalBasePath);
}

$document = '<!doctype html><html><head><title>Test</title></head><body><main>Inhalt</main></body></html>';
$injected = estab_session_ui_inject_document($document, $markup);
$assert(substr_count($injected, 'data-estab-session-bar') === 1, 'document did not receive one session bar');
$assert(substr_count($injected, 'estab-ui.css') === 1, 'document did not receive shared stylesheet');
$assert(
    strpos($injected, 'data-estab-session-bar') < strpos($injected, '<main>'),
    'session bar was not inserted at the beginning of the body'
);
$assert(
    estab_session_ui_inject_document($injected, $markup) === $injected,
    'document injection duplicated the session bar'
);
$markerTextDocument = str_replace(
    'Inhalt',
    'Nutztext data-estab-session-bar bleibt inert',
    $document
);
$markerTextInjected = estab_session_ui_inject_document($markerTextDocument, $markup);
$assert(
    estab_session_ui_document_has_bar($markerTextInjected)
        && str_contains($markerTextInjected, 'data-estab-logout-form'),
    'plain marker text suppressed the real session bar'
);
$fragment = estab_session_ui_inject_document('<table><tr><td>Inhalt</td></tr></table>', $markup);
$assert(
    str_contains($fragment, 'data-estab-session-bar')
        && str_contains($fragment, 'estab-ui.css'),
    'authenticated HTML fragment did not receive session chrome'
);
$assert(
    estab_session_ui_response_is_html([], 200)
        && estab_session_ui_response_is_html(
            ['Content-Type: text/html; charset=UTF-8'],
            422
        ),
    'HTML response classification rejected a supported document'
);
$assert(
    !estab_session_ui_response_is_html(
        ['Content-Type: text/plain; charset=UTF-8'],
        403
    )
        && !estab_session_ui_response_is_html(
            ['Content-Type: application/json; charset=UTF-8'],
            200
        )
        && !estab_session_ui_response_is_html([], 303),
    'session UI would alter a plain-text, JSON, or redirect response'
);
$assert(
    estab_session_ui_is_embedded_frame(['embedded' => '1'])
        && !estab_session_ui_is_embedded_frame(['embedded' => 1])
        && !estab_session_ui_is_embedded_frame([]),
    'frameset embedding hint is not strict'
);

$invalidTokenRejected = false;
try {
    estab_session_ui_markup($identity, 'not-a-token');
} catch (InvalidArgumentException) {
    $invalidTokenRejected = true;
}
$assert($invalidTokenRejected, 'invalid logout CSRF token accepted by renderer');

$logout = estab_logout_capture($identity, ['REMOTE_ADDR' => '192.0.2.44'], 'session-123');
$assert(
    is_array($logout)
        && $logout['benutzer'] === 'Müller & "Ada"'
        && $logout['kuerzel'] === 'ada001'
        && $logout['sid'] === 'session-123'
        && $logout['ip'] === '192.0.2.44',
    'logout capture did not preserve the validated current session'
);
$assert(estab_logout_capture([], [], 'session-123') === null, 'anonymous logout capture accepted');
$assert(estab_logout_capture($identity, [], '') === null, 'empty session ID accepted for logout');
$auditPayload = estab_logout_audit_payload($logout);
$assert(
    !str_contains($auditPayload, 'session-123')
        && str_contains($auditPayload, 'sha256:' . hash('sha256', 'session-123')),
    'logout audit persisted a raw session credential'
);

$root = dirname(__DIR__, 2);
$bufferedSurfaces = [
    'index.php',
    '4fach/mainindex.php',
    '4fach/anhang.php',
    '4fach/katgoedt.php',
    '4fach/vordrucke.php',
    '4fach/nachwea.php',
    '4fach/counter.php',
    '4fach/status.php',
    '4fueltg/ue_ltg.php',
    'stabetb/etb.php',
    'fmtbb/tbb.php',
];
foreach ($bufferedSurfaces as $surface) {
    $source = file_get_contents($root . '/' . $surface);
    $assert(
        is_string($source) && str_contains($source, 'estab_session_ui_start'),
        $surface . ' does not install the shared authenticated session UI'
    );
}
$navigationSource = file_get_contents($root . '/4fach/vorgaben.php');
$assert(
    is_string($navigationSource)
        && str_contains($navigationSource, 'estab_session_ui_current_markup'),
    'persistent navigation does not render compact session UI'
);
$mainSource = file_get_contents($root . '/4fach/mainindex.php');
$mainBufferPosition = is_string($mainSource)
    ? strpos($mainSource, 'estab_session_ui_start')
    : false;
$mainRequestPosition = is_string($mainSource)
    ? strpos($mainSource, '$returnValue = array')
    : false;
$assert(
    is_int($mainBufferPosition)
        && is_int($mainRequestPosition)
        && $mainBufferPosition < $mainRequestPosition,
    'main controller starts session UI buffering after request output can begin'
);
$framesetSource = file_get_contents($root . '/4fach/index.php');
$assert(
    is_string($framesetSource)
        && str_contains($framesetSource, './counter.php?embedded=1')
        && str_contains($framesetSource, './status.php?embedded=1'),
    'frameset helper pages would duplicate the persistent navigation bar'
);
$toolsSource = file_get_contents($root . '/4fach/tools.php');
$assert(
    is_string($toolsSource)
        && str_contains($toolsSource, '$entryCount = count ($conf_empf)')
        && str_contains($toolsSource, 'if ( ($i <= $entryCount) and')
        && !str_contains($toolsSource, '$statusalt'),
    'authenticated status rendering does not bound dynamic function entries'
);

$endpointSource = file_get_contents($root . '/4fach/logout.php');
$assert(
    is_string($endpointSource)
        && str_contains($endpointSource, "if (\$method !== 'POST')")
        && str_contains($endpointSource, 'estab_csrf_require_post')
        && str_contains($endpointSource, "'logout_action'")
        && str_contains($endpointSource, '303'),
    'standalone logout endpoint lacks its POST, CSRF, action, or redirect contract'
);
$authSource = file_get_contents($root . '/app/auth.php');
$assert(
    is_string($authSource)
        && str_contains($authSource, 'WHERE `kuerzel` = ? AND `sid` = ?'),
    'database logout is not bound to the concrete session'
);
$logoutSource = file_get_contents($root . '/app/logout.php');
$destroyPosition = is_string($logoutSource)
    ? strpos($logoutSource, 'estab_auth_destroy_session')
    : false;
$connectPosition = is_string($logoutSource)
    ? strpos($logoutSource, 'estab_auth_connect')
    : false;
$assert(
    is_int($destroyPosition)
        && is_int($connectPosition)
        && $destroyPosition < $connectPosition,
    'local session destruction does not precede logout persistence'
);

$previousErrorLog = ini_get('error_log');
$logoutErrorLog = tempnam(sys_get_temp_dir(), 'estab-logout-test-');
if ($logoutErrorLog === false) {
    throw new RuntimeException('could not create logout failure test log');
}
ini_set('error_log', $logoutErrorLog);
session_start();
$_SESSION = $identity;
$sessionCookieName = session_name();
$_COOKIE[$sessionCookieName] = session_id();
foreach (['vStab_benutzer', 'vStab_kuerzel', 'vStab_funktion', 'vStab_rolle'] as $cookieName) {
    $_COOKIE[$cookieName] = 'legacy';
}
$failedPersistenceLogout = estab_logout_current_session(
    [
        'server' => '127.0.0.1:1',
        'user' => 'unreachable',
        'password' => 'unreachable',
        'datenbank' => 'unreachable',
    ],
    'nv_benutzer',
    'nv_protokoll',
    ['REMOTE_ADDR' => '192.0.2.45']
);
if (is_string($previousErrorLog)) {
    ini_set('error_log', $previousErrorLog);
}
@unlink($logoutErrorLog);
$assert(
    session_status() === PHP_SESSION_NONE
        && $_SESSION === []
        && !isset($_COOKIE[$sessionCookieName])
        && !isset($_COOKIE['vStab_benutzer'])
        && !isset($_COOKIE['vStab_kuerzel'])
        && !isset($_COOKIE['vStab_funktion'])
        && !isset($_COOKIE['vStab_rolle']),
    'database failure kept local session data or application cookies alive'
);
$assert(
    $failedPersistenceLogout['snapshot']['kuerzel'] === 'ada001'
        && $failedPersistenceLogout['account_deactivated'] === false
        && $failedPersistenceLogout['audit_recorded'] === false,
    'database failure logout did not report its safe local-only result'
);

echo "session UI security: OK ({$assertions} assertions)\n";
