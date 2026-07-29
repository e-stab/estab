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
$assertThrows = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (InvalidArgumentException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
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
        && str_contains($markup, 'data-estab-navigation')
        && str_contains($markup, 'data-estab-nav-key="overview"')
        && str_contains($markup, '>Übersicht</span>')
        && str_contains($markup, 'name="logout_action" value="logout"')
        && str_contains($markup, '>Abmelden</button>'),
    'navigation or logout form contract incomplete'
);
$assert(
    str_contains($markup, 'name="csrf_token" value="' . $token . '"'),
    'logout form CSRF token missing'
);

$compact = estab_session_ui_markup($identity, $token, true);
$assert(
    str_contains($compact, 'estab-session-bar-compact')
        && str_contains($compact, '<summary>Bereich wechseln</summary>')
        && !str_contains($compact, 'data-estab-mainframe-guard'),
    'compact frame presentation missing'
);
$sidebarMarkup = estab_session_ui_markup(
    $identity,
    $token,
    true,
    [],
    false,
    true
);
$assert(
    str_contains($sidebarMarkup, 'estab-session-bar-compact')
        && str_contains($sidebarMarkup, 'estab-session-bar-sidebar')
        && str_contains(
            $sidebarMarkup,
            'data-estab-navigation-mode="sidebar"'
        )
        && str_contains($sidebarMarkup, '<h2>Bereiche</h2>')
        && str_contains($sidebarMarkup, '>Nachrichten</span>')
        && str_contains($sidebarMarkup, 'data-estab-user-code="ada001"')
        && str_contains($sidebarMarkup, 'data-estab-logout-form')
        && !str_contains($sidebarMarkup, '<details')
        && !str_contains($sidebarMarkup, '<summary')
        && !str_contains($sidebarMarkup, 'data-estab-mainframe-guard'),
    'always-visible authenticated sidebar presentation is incomplete'
);
$sidebarIdentityMarkup = estab_session_ui_markup(
    $identity,
    $token,
    true,
    [],
    false,
    true,
    false
);
$assert(
    str_contains($sidebarIdentityMarkup, 'estab-session-bar-sidebar')
        && str_contains($sidebarIdentityMarkup, 'data-estab-user-code="ada001"')
        && str_contains($sidebarIdentityMarkup, 'data-estab-logout-form')
        && !str_contains(
            $sidebarIdentityMarkup,
            'data-estab-navigation-mode='
        ),
    'split sidebar identity unexpectedly contains the area navigation'
);
$assertThrows(
    static fn (): string => estab_session_ui_markup(
        $identity,
        $token,
        false,
        [],
        false,
        true
    ),
    'authenticated sidebar accepted non-compact presentation'
);
$assert(
    str_contains($markup, 'data-estab-mainframe-guard')
        && str_contains($markup, 'window.name==="mainframe"')
        && str_contains($markup, 'bar.remove()'),
    'full session bar is not suppressed in the composed main frame'
);
$assert(
    substr_count($markup, '<script data-estab-dirty-guard>') === 1
        && str_contains($markup, 'form[data-estab-dirty-guard]')
        && str_contains($markup, 'data-estab-dirty-initial')
        && str_contains($markup, 'event.submitter')
        && str_contains($markup, 'replace-editor-with-standard')
        && str_contains($markup, 'replace-standard')
        && str_contains($markup, 'nur in einem Datenbankbackup')
        && str_contains($markup, 'window.confirm(')
        && str_contains($markup, 'Ungespeicherte Eingaben')
        && str_contains($markup, '.estab-button-login')
        && str_contains($markup, 'window.opener')
        && str_contains($markup, 'app.location.assign(link.href)')
        && str_contains($markup, 'app.name=targetName')
        && str_contains($markup, 'var popupContext=false'),
    'shared navigation does not guard explicitly marked unsaved forms'
);
$popupMarkup = estab_session_ui_markup(
    $identity,
    $token,
    false,
    [],
    true
);
$assert(
    str_contains($popupMarkup, 'data-estab-popup-ui')
        && str_contains($popupMarkup, 'var popupContext=true')
        && !str_contains($markup, 'data-estab-popup-ui'),
    'opener coordination is not restricted to explicit application popups'
);
$publicMarkup = estab_session_ui_public_markup(
    false,
    ['SCRIPT_NAME' => '/stabinfo/index.php']
);
$assert(
    str_contains($publicMarkup, 'data-estab-public-bar')
        && str_contains($publicMarkup, 'Nicht angemeldet')
        && str_contains($publicMarkup, '>Anmelden</a>')
        && str_contains($publicMarkup, 'data-estab-navigation')
        && str_contains(
            $publicMarkup,
            'data-estab-nav-key="bos-info" aria-current="page"'
        )
        && !str_contains($publicMarkup, 'data-estab-session-bar')
        && !str_contains($publicMarkup, 'data-estab-logout-form'),
    'public navigation falsely claims a session or omits its login route'
);
$assert(
    estab_session_ui_document_has_bar($publicMarkup),
    'public navigation bar was not detected as shared chrome'
);
$destinationPublicMarkup = estab_session_ui_public_markup(
    true,
    ['SCRIPT_NAME' => '/4fach/vorgaben.php'],
    'incident-log'
);
$assert(
    str_contains(
        $destinationPublicMarkup,
        'href="/4fach/index.php?next=incident-log"'
    )
        && str_contains(
            $destinationPublicMarkup,
            '<summary>Bereich wechseln</summary>'
    ),
    'public frame login control lost its protected destination'
);
$publicSidebarMarkup = estab_session_ui_public_markup(
    true,
    ['SCRIPT_NAME' => '/4fach/vorgaben.php'],
    'incident-log',
    false,
    true
);
$assert(
    str_contains($publicSidebarMarkup, 'estab-session-bar-sidebar')
        && str_contains($publicSidebarMarkup, 'data-estab-public-bar')
        && str_contains(
            $publicSidebarMarkup,
            'data-estab-navigation-mode="sidebar"'
        )
        && str_contains(
            $publicSidebarMarkup,
            'href="/4fach/index.php?next=incident-log"'
        )
        && substr_count(
            $publicSidebarMarkup,
            'data-estab-navigation-locked'
        ) === 6
        && !str_contains($publicSidebarMarkup, '<details')
        && !str_contains($publicSidebarMarkup, '<summary')
        && !str_contains($publicSidebarMarkup, 'data-estab-session-bar')
        && !str_contains($publicSidebarMarkup, 'data-estab-logout-form'),
    'public sidebar disclosed a session, lost its destination, or became expandable'
);
$assertThrows(
    static fn (): string => estab_session_ui_public_markup(
        false,
        [],
        null,
        false,
        true
    ),
    'public sidebar accepted non-compact presentation'
);
$assert(
    estab_session_ui_login_destination(
        ['next' => 'tracking'],
        []
    ) === 'tracking'
        && estab_session_ui_login_destination(
            ['next' => 'tracking'],
            ['next' => 'incident-log']
        ) === 'incident-log'
        && estab_session_ui_login_destination(
            ['next' => 'administration'],
            []
        ) === null,
    'request-bound session UI destination resolution is unsafe'
);
$adminServer = [
    'SCRIPT_NAME' => '/4fadm/admin.php',
    'REMOTE_USER' => 'admin<&',
];
$adminPublicMarkup = estab_session_ui_public_markup(false, $adminServer);
$assert(
    estab_session_ui_admin_user($adminServer) === 'admin<&'
        && str_contains(
            $adminPublicMarkup,
            'data-estab-admin-user="admin&lt;&amp;"'
        )
        && str_contains($adminPublicMarkup, 'Administrationszugang')
        && str_contains(
            $adminPublicMarkup,
            'Kein eStab-Funktionskonto angemeldet'
        )
        && str_contains(
            $adminPublicMarkup,
            'data-estab-nav-key="administration" aria-current="page"'
        )
        && !str_contains($adminPublicMarkup, 'admin<&'),
    'Basic-Auth administration context is missing or unescaped'
);
$adminSessionMarkup = estab_session_ui_markup(
    $identity,
    $token,
    false,
    $adminServer
);
$assert(
    str_contains($adminSessionMarkup, 'data-estab-session-bar')
        && str_contains(
            $adminSessionMarkup,
            'data-estab-admin-user="admin&lt;&amp;"'
        )
        && str_contains($adminSessionMarkup, 'Müller &amp; &quot;Ada&quot;'),
    'combined eStab and Basic-Auth identities are not distinguishable'
);
$assert(
    estab_session_ui_admin_user([
        'SCRIPT_NAME' => '/index.php',
        'REMOTE_USER' => 'admin',
    ]) === null,
    'REMOTE_USER leaked into a non-administrative route'
);

$originalPublicUrl = getenv('ESTAB_PUBLIC_URL');
$originalBasePath = getenv('ESTAB_BASE_PATH');
putenv('ESTAB_PUBLIC_URL=https://example.invalid/gateway');
putenv('ESTAB_BASE_PATH=dispatch/site');
$scopedMarkup = estab_session_ui_markup($identity, $token);
$scopedStylesheet = estab_session_ui_stylesheet();
$scopedFrameRefresh = estab_session_ui_frame_refresh_script();
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
$assert(
    str_contains(
        $scopedMarkup,
        'href="https://example.invalid/gateway/dispatch/site/"'
    ),
    'session home link did not preserve public URL and deployment base path'
);
$assert(
    $scopedFrameRefresh
        === 'FramesVeraendern('
            . '"https://example.invalid/gateway/dispatch/site/4fach/vorgaben.php",'
            . '"vorgaben",'
            . '"https://example.invalid/gateway/dispatch/site/4fach/mainindex.php",'
            . '"mainframe");',
    'frame refresh did not preserve public URL and deployment base path'
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
putenv('ESTAB_PUBLIC_URL=/');
putenv('ESTAB_BASE_PATH=');
$rootFrameRefresh = estab_session_ui_frame_refresh_script();
$assert(
    $rootFrameRefresh
        === 'FramesVeraendern('
            . '"/4fach/vorgaben.php","vorgaben",'
            . '"/4fach/mainindex.php","mainframe");'
        && !str_contains($rootFrameRefresh, 'counter')
        && !str_contains($rootFrameRefresh, 'status')
        && !str_contains($rootFrameRefresh, '"//4fach/'),
    'root frame refresh contains a stale frame or unsafe URL'
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
$stylesheetTextDocument = str_replace(
    'Inhalt',
    'Nutztext estab-ui.css ist kein Stylesheet-Link',
    $document
);
$stylesheetTextInjected = estab_session_ui_inject_document(
    $stylesheetTextDocument,
    $markup
);
$assert(
    estab_session_ui_document_has_stylesheet($stylesheetTextInjected)
        && substr_count($stylesheetTextInjected, 'estab-ui.css') === 2,
    'plain stylesheet filename suppressed the real shared stylesheet'
);
$prelinkedDocument = str_replace(
    '</head>',
    estab_session_ui_stylesheet() . '</head>',
    $document
);
$prelinkedInjected = estab_session_ui_inject_document($prelinkedDocument, $markup);
$assert(
    substr_count($prelinkedInjected, 'estab-ui.css') === 1,
    'existing shared stylesheet link was duplicated'
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
        && !estab_session_ui_response_is_html(
            ['Content-Type: application/zip'],
            200
        )
        && !estab_session_ui_response_is_html([], 303),
    'session UI would alter a plain-text, JSON, ZIP, or redirect response'
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
    'stabinfo/l_index.php',
    '4fach/info.php',
    'language/german/helptext.php',
    '4fadm/admin.php',
    '4fadm/incidents.php',
    '4fadm/incident_export.php',
    '4fadm/users.php',
    '4fadm/make_fkt.php',
    '4fadm/set_number_after_crash.php',
    '4fadm/export.php',
    '4fadm/system_status.php',
    '4fach/resetpic.php',
];
foreach ($bufferedSurfaces as $surface) {
    $source = file_get_contents($root . '/' . $surface);
    $assert(
        is_string($source) && str_contains($source, 'estab_session_ui_start'),
        $surface . ' does not install the shared authenticated session UI'
    );
}
$guardedEditSurfaces = [
    '4fach/4fachform.php',
    '4fach/anhang.php',
    '4fach/katgoedt.php',
    'stabetb/etb.php',
    'fmtbb/tbb.php',
    '4fadm/make_fkt.php',
    '4fadm/set_number_after_crash.php',
    '4fadm/export.php',
    '4fadm/incidents.php',
    '4fadm/users.php',
    '4fach/resetpic.php',
];
foreach ($guardedEditSurfaces as $surface) {
    $source = file_get_contents($root . '/' . $surface);
    $assert(
        is_string($source) && str_contains(
            $source,
            'data-estab-dirty-guard'
        ),
        $surface . ' does not opt its edit form into navigation loss protection'
    );
}
$matrixSource = file_get_contents($root . '/4fadm/make_fkt.php');
$counterSource = file_get_contents(
    $root . '/4fadm/set_number_after_crash.php'
);
$messageFormSource = file_get_contents($root . '/4fach/4fachform.php');
$assert(
    is_string($matrixSource)
        && is_string($counterSource)
        && is_string($messageFormSource)
        && str_contains($matrixSource, 'data-estab-dirty-initial')
        && str_contains($counterSource, 'data-estab-dirty-initial')
        && str_contains($messageFormSource, 'data-estab-dirty-initial'),
    'server-rerendered unsaved values are not marked dirty'
);
$assert(
    str_contains($matrixSource, 'if (is_array($submitted))')
        && str_contains(
            $matrixSource,
            '$error !== null && is_array($submitted)'
        ),
    'matrix persistence errors do not preserve and guard submitted values'
);
$helpSource = file_get_contents($root . '/language/german/helptext.php');
$infoSource = file_get_contents($root . '/4fach/info.php');
$assert(
    is_string($helpSource)
        && is_string($infoSource)
        && str_contains(
            $helpSource,
            'estab_session_ui_start($_SESSION, false, true)'
        )
        && str_contains(
            $infoSource,
            'estab_session_ui_start($_SESSION, false, true)'
        ),
    'help and problem windows do not opt into explicit opener coordination'
);
$navigationSource = file_get_contents($root . '/4fach/vorgaben.php');
$navigationStatusPosition = is_string($navigationSource)
    ? strpos($navigationSource, '<?= $statusMarkup ?>')
    : false;
$navigationWorkflowPosition = is_string($navigationSource)
    ? strpos($navigationSource, 'data-estab-workflow-menu')
    : false;
$navigationAreasPosition = is_string($navigationSource)
    ? strrpos($navigationSource, 'estab_navigation_markup(')
    : false;
$assert(
    is_string($navigationSource)
        && str_contains($navigationSource, 'estab_session_ui_current_markup')
        && str_contains(
            $navigationSource,
            'estab_navigation_markup('
        )
        && str_contains($navigationSource, 'data-estab-sidebar-root')
        && str_contains($navigationSource, 'data-estab-workflow-menu')
        && str_contains($navigationSource, 'estab_sidebar_status_markup')
        && str_contains(
            $navigationSource,
            'estab_sidebar_queue_notification'
        )
        && str_contains($navigationSource, 'estab_sidebar_queue_count')
        && str_contains($navigationSource, 'estab_sidebar_audio_markup')
        && str_contains(
            $navigationSource,
            'data-estab-sidebar-workspace-link'
        )
        && str_contains(
            $navigationSource,
            'estab_sidebar_status_refresh_script'
        )
        && !str_contains($navigationSource, 'getoutqueuecount(')
        && !str_contains($navigationSource, 'getviewerqueuecount(')
        && !str_contains($navigationSource, 'getdonecount(')
        && !str_contains($navigationSource, 'db_operation.php')
        && !str_contains($navigationSource, 'tools.php')
        && !str_contains($navigationSource, 'fkt_rolle.inc.php')
        && str_contains(
            $navigationSource,
            'estab_sidebar_fetch_configured_positions'
        )
        && is_int($navigationStatusPosition)
        && is_int($navigationWorkflowPosition)
        && is_int($navigationAreasPosition)
        && $navigationStatusPosition < $navigationWorkflowPosition
        && $navigationWorkflowPosition < $navigationAreasPosition,
    'persistent navigation does not render a resilient unified live sidebar'
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
        && substr_count($framesetSource, '<iframe') === 2
        && substr_count($framesetSource, 'name="vorgaben"') === 1
        && substr_count($framesetSource, 'name="mainframe"') === 1
        && str_contains($framesetSource, 'data-estab-message-workspace')
        && str_contains($framesetSource, 'data-estab-mobile-menu-return')
        && str_contains(
            $framesetSource,
            "event.data === 'estab:show-content'"
        )
        && str_contains(
            $framesetSource,
            'event.source === sidebar.contentWindow'
        )
        && str_contains($framesetSource, 'content.scrollIntoView')
        && str_contains(
            $framesetSource,
            'content.focus({preventScroll: true})'
        )
        && str_contains($framesetSource, "content.addEventListener('load'")
        && str_contains(
            $framesetSource,
            'window.requestAnimationFrame(showContent)'
        )
        && !str_contains($framesetSource, 'counter.php')
        && !str_contains($framesetSource, 'status.php')
        && !str_contains(strtolower($framesetSource), '<frameset'),
    'message workspace does not contain exactly its sidebar and content frames'
);
$bosWorkspaceSource = file_get_contents($root . '/stabinfo/index.php');
$bosNavigationSource = file_get_contents($root . '/stabinfo/l_index.php');
$bosWelcomeSource = file_get_contents($root . '/stabinfo/f_info.php');
$bosStylesheetSource = file_get_contents($root . '/estab-ui.css');
$assert(
    is_string($bosWorkspaceSource)
        && substr_count($bosWorkspaceSource, '<iframe') === 2
        && substr_count($bosWorkspaceSource, 'name="status"') === 1
        && substr_count($bosWorkspaceSource, 'name="mainframe"') === 1
        && str_contains($bosWorkspaceSource, 'data-estab-bos-workspace')
        && str_contains($bosWorkspaceSource, 'src="./l_index.php"')
        && str_contains($bosWorkspaceSource, 'src="./f_info.php"')
        && str_contains(
            $bosWorkspaceSource,
            'data-estab-mobile-menu-return'
        )
        && str_contains(
            $bosWorkspaceSource,
            "event.data === 'estab:show-content'"
        )
        && str_contains(
            $bosWorkspaceSource,
            'event.source === sidebar.contentWindow'
        )
        && str_contains(
            $bosWorkspaceSource,
            'data-estab-bos-responsive-style'
        )
        && str_contains(
            $bosWorkspaceSource,
            "body.classList.add('estab-bos-embedded-content')"
        )
        && !str_contains(strtolower($bosWorkspaceSource), '<frameset'),
    'BOS workspace is not the responsive two-frame application workspace'
);
$assert(
    is_string($bosNavigationSource)
        && str_contains($bosNavigationSource, 'estab_session_ui_current_markup')
        && str_contains($bosNavigationSource, 'data-estab-bos-sidebar')
        && str_contains(
            $bosNavigationSource,
            'data-estab-bos-document-navigation'
        )
        && str_contains($bosNavigationSource, 'target="mainframe"')
        && str_contains(
            $bosNavigationSource,
            'data-estab-bos-document-link'
        )
        && str_contains(
            $bosNavigationSource,
            "window.parent.postMessage("
        )
        && str_contains(
            $bosNavigationSource,
            "'estab:show-content'"
        )
        && !str_contains($bosNavigationSource, '<details')
        && !str_contains($bosNavigationSource, '<summary')
        && !str_contains($bosNavigationSource, '<table')
        && !str_contains($bosNavigationSource, 'style='),
    'BOS sidebar is not an always-visible shared navigation and document menu'
);
foreach ([
    'Buchstabier.html',
    'Kartendatum.html',
    'IuK-InfoPack.html',
    'Orgas.html',
    'FF-Rufnamenschema.html',
    'DRK%20Rufnamenschema.html',
    'THWFuRNR.html',
] as $bosDocument) {
    $assert(
        str_contains((string) $bosNavigationSource, $bosDocument),
        'BOS sidebar lost its historical document link: ' . $bosDocument
    );
}
$assert(
    is_string($bosWelcomeSource)
        && str_contains($bosWelcomeSource, 'data-estab-bos-welcome')
        && str_contains($bosWelcomeSource, '../estab-ui.css')
        && is_string($bosStylesheetSource)
        && str_contains(
            $bosStylesheetSource,
            '.estab-bos-document-navigation'
        )
        && str_contains(
            $bosStylesheetSource,
            '.estab-bos-embedded-content'
        )
        && str_contains(
            $bosStylesheetSource,
            '.estab-bos-welcome'
        ),
    'BOS welcome and responsive content styles are incomplete'
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
        && str_contains(
            $endpointSource,
            "estab_application_url('4fach/index.php')"
        )
        && str_contains($endpointSource, '303'),
    'standalone logout endpoint lacks its POST, CSRF, action, safe URL, or redirect contract'
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
