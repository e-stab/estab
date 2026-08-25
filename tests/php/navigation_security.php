<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/navigation.php';

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

$originalPublicUrl = getenv('ESTAB_PUBLIC_URL');
$originalBasePath = getenv('ESTAB_BASE_PATH');
putenv('ESTAB_PUBLIC_URL=/');
putenv('ESTAB_BASE_PATH=');

$navigationSource = file_get_contents(
    dirname(__DIR__, 2) . '/app/navigation.php'
);
$assert(is_string($navigationSource), 'navigation source cannot be read');
$selectDutyStart = strpos(
    $navigationSource,
    'function estab_navigation_select_duty('
);
$selectDutyEnd = is_int($selectDutyStart)
    ? strpos(
        $navigationSource,
        'function estab_navigation_login_destination_field(',
        $selectDutyStart
    )
    : false;
$selectDutySource = is_int($selectDutyStart)
    && is_int($selectDutyEnd)
    && $selectDutyEnd > $selectDutyStart
    ? substr(
        $navigationSource,
        $selectDutyStart,
        $selectDutyEnd - $selectDutyStart
    )
    : '';
$assert(
    $selectDutySource !== ''
        && str_contains(
            $selectDutySource,
            "['GET', 'HEAD', 'POST']"
        )
        && str_contains(
            $selectDutySource,
            "\$session['estab_pending_navigation_key'] = \$destinationKey"
        )
        && str_contains(
            $selectDutySource,
            "unset(\$session['estab_pending_navigation_key'])"
        )
        && str_contains(
            $selectDutySource,
            "estab_navigation_url_for_key('command-post')"
        )
        && str_contains($selectDutySource, "'#meine-dienstfunktionen'")
        && str_contains($selectDutySource, '303')
        && !str_contains($selectDutySource, 'text/plain')
        && !str_contains($selectDutySource, 'estab_auth_require_session('),
    'missing STRICT function selection can still end in a plain-text dead end or replay an unsafe destination'
);
$assert(
    estab_navigation_strict_duty_selection_required(null)
        && estab_navigation_strict_duty_selection_required([
            'estab_permission_mode' => 'STRICT',
        ])
        && estab_navigation_strict_duty_selection_required([
            'estab_permission_mode' => 'STRICT',
            'duty_assignment_id' => 0,
        ])
        && estab_navigation_strict_duty_selection_required([
            'estab_permission_mode' => 'STRICT',
            'duty_assignment_id' => '999999999999999999999999999999',
        ])
        && estab_navigation_strict_duty_selection_required([
            'estab_permission_mode' => 'INVALID',
            'duty_assignment_id' => 73,
        ])
        && !estab_navigation_strict_duty_selection_required([
            'estab_permission_mode' => 'STRICT',
            'duty_assignment_id' => 73,
        ])
        && !estab_navigation_strict_duty_selection_required([
            'estab_permission_mode' => 'LOOSE',
        ])
        && !estab_navigation_strict_duty_selection_required([
            'estab_permission_mode' => 'LOOSE',
            'duty_assignment_id' => 'not-authority',
        ]),
    'STRICT/LOOSE direct-route duty-selection predicate is not fail-closed'
);
$strictLoginSession = [];
$looseLoginSession = ['estab_pending_navigation_key' => 'tracking'];
$invalidLoginSession = ['estab_pending_navigation_key' => 'tracking'];
$assert(
    estab_navigation_login_landing_key(
        $strictLoginSession,
        'STRICT',
        'incident-log'
    ) === 'command-post'
        && ($strictLoginSession['estab_pending_navigation_key'] ?? null)
            === 'incident-log'
        && estab_navigation_login_landing_key(
            $looseLoginSession,
            'LOOSE',
            'incident-log'
        ) === 'incident-log'
        && !isset($looseLoginSession['estab_pending_navigation_key']),
    'post-login landing does not bootstrap STRICT through duty selection or continue LOOSE directly'
);
$assertThrows(
    static function () use (&$invalidLoginSession): void {
        estab_navigation_login_landing_key(
            $invalidLoginSession,
            'INVALID',
            'incident-log'
        );
    },
    'invalid post-login permission mode did not fail closed'
);
$assert(
    !isset($invalidLoginSession['estab_pending_navigation_key']),
    'invalid post-login permission mode retained a stale protected destination'
);

$directDutyRoutes = [
    '4fach/anhang.php' => '"messages"',
    '4fach/download.php' => '$loginDestination',
    '4fach/katgoedt.php' => "'messages'",
    '4fach/nachwea.php' => '"tracking"',
    '4fach/vordrucke.php' => "'forms'",
    '4fueltg/ue_ltg.php' => '"message-overview"',
    'fmtbb/tbb.php' => '"technical-log"',
    'stabetb/etb.php' => '"incident-log"',
];
foreach ($directDutyRoutes as $relativePath => $destinationSource) {
    $routeSource = file_get_contents(
        dirname(__DIR__, 2) . '/' . $relativePath
    );
    $assert(
        is_string($routeSource)
            && str_contains(
                $routeSource,
                'estab_navigation_require_selected_duty'
            )
            && str_contains($routeSource, '$_SESSION')
            && str_contains($routeSource, $destinationSource)
            && strpos(
                $routeSource,
                'estab_navigation_require_selected_duty'
            ) > strpos($routeSource, 'estab_navigation_require_session'),
        'protected direct route does not enforce the mode-aware STRICT '
            . 'selection with its safe return target: ' . $relativePath
    );
}

$areas = estab_navigation_areas();
$services = estab_navigation_services();
$s1NavigationIdentity = [
    'funktion' => 'S1',
    'rolle' => 'Stab',
    'estab_permission_mode' => 'LOOSE',
];
$s2NavigationIdentity = [
    'funktion' => 'S2',
    'rolle' => 'Stab',
    'estab_permission_mode' => 'LOOSE',
];
$ldfNavigationIdentity = [
    'funktion' => 'LdF',
    'rolle' => 'Fernmelder',
    'estab_permission_mode' => 'LOOSE',
];
$s1WithS2NavigationIdentity = $s1NavigationIdentity + [
    'estab_additional_functions' => [
        ['funktion' => 'S2', 'rolle' => 'Stab'],
    ],
];
$assert(
    array_column($areas, 'key') === [
        'overview',
        'messages',
        'command-post',
        'message-overview',
        'forms',
        'incident-log',
        'technical-log',
        'tracking',
        'bos-info',
    ],
    'operational navigation order changed'
);
$assert(
    array_column($areas, 'label') === [
        'Übersicht',
        'Nachrichtenvordruck',
        'Führungsstellenbetrieb',
        'Meldungsübersicht',
        'Vordrucke',
        'Einsatztagebuch (ETB)',
        'Technisches Betriebsbuch (TBB)',
        'Nachweisung',
        'BOS-Info',
    ],
    'operational navigation labels changed'
);
$assert(
    array_column($areas, 'short_label') === [
        'Übersicht',
        'Nachrichten',
        'Führungsstelle',
        'Meldungen',
        'Vordrucke',
        'ETB',
        'TBB',
        'Nachweisung',
        'BOS-Info',
    ],
    'sidebar navigation labels changed'
);
$assert(
    array_column($areas, 'path') === [
        'index.php',
        '4fach/index.php',
        '4fach/fuehrungsstelle.php',
        '4fueltg/ue_ltg.php',
        '4fach/vordrucke.php',
        'stabetb/etb.php',
        'fmtbb/tbb.php',
        '4fach/nachwea.php?nwalle',
        'stabinfo/index.php',
    ],
    'operational navigation targets changed'
);
$assert(
    array_column($services, 'key') === ['administration', 'handbook']
        && array_column($services, 'path') === [
            '4fadm/admin.php',
            'handbuch/',
        ]
        && array_column($services, 'short_label') === [
            'Administration',
            'Handbuch',
        ]
        && $services[0]['hint'] === 'Technischer Zugang',
    'secondary service navigation contract changed'
);
$assert(
    estab_navigation_item_url($areas[0]) === '/'
        && estab_navigation_item_url($areas[1]) === '/4fach/index.php'
        && estab_navigation_item_url($areas[7])
            === '/4fach/nachwea.php?nwalle'
        && estab_navigation_item_url($services[1]) === '/handbuch/',
    'root navigation URLs are not canonical'
);
$assert(
    estab_navigation_login_destination_field(null) === ''
        && estab_navigation_login_destination_field('incident-log')
            === '<input type="hidden" name="next" value="incident-log">',
    'per-form login destination field is missing or malformed'
);
$assertThrows(
    static fn (): string =>
        estab_navigation_login_destination_field('administration'),
    'public service was accepted as a per-form login destination'
);
$assertThrows(
    static fn (): string =>
        estab_navigation_login_destination_field('https://attacker.invalid'),
    'external URL was accepted as a per-form login destination'
);

putenv('ESTAB_PUBLIC_URL=https://example.invalid/gateway');
putenv('ESTAB_BASE_PATH=dispatch/site');
$assert(
    estab_navigation_item_url($areas[0])
        === 'https://example.invalid/gateway/dispatch/site/'
        && estab_navigation_item_url($areas[5])
            === 'https://example.invalid/gateway/dispatch/site/stabetb/etb.php'
        && estab_navigation_login_url()
            === 'https://example.invalid/gateway/dispatch/site/4fach/index.php'
                . '?login_flow=existing'
        && estab_navigation_login_url('incident-log')
            === 'https://example.invalid/gateway/dispatch/site/4fach/index.php'
                . '?login_flow=existing&next=incident-log'
        && estab_navigation_login_url('incident-log', true)
            === 'https://example.invalid/gateway/dispatch/site/4fach/index.php'
                . '?login_flow=existing&next=incident-log&interrupted=1'
        && estab_navigation_login_content_url('messages', true)
            === 'https://example.invalid/gateway/dispatch/site/4fach/'
                . 'mainindex.php?login_flow=existing&next=messages'
                . '&interrupted=1'
        && estab_navigation_login_redirect_url(
            'forms',
            false,
            ['HTTP_SEC_FETCH_DEST' => 'document']
        ) === 'https://example.invalid/gateway/dispatch/site/4fach/'
                . 'index.php?login_flow=existing&next=forms'
        && estab_navigation_login_redirect_url(
            'messages',
            true,
            ['HTTP_SEC_FETCH_DEST' => 'iframe']
        ) === 'https://example.invalid/gateway/dispatch/site/4fach/'
                . 'mainindex.php?login_flow=existing&next=messages'
                . '&interrupted=1'
        && estab_navigation_login_redirect_url(
            'messages',
            false,
            [],
            true
        ) === 'https://example.invalid/gateway/dispatch/site/4fach/'
                . 'mainindex.php?login_flow=existing&next=messages'
        && estab_navigation_item_url($services[1])
            === 'https://example.invalid/gateway/dispatch/site/handbuch/',
    'navigation URLs did not preserve public URL and deployment base path'
);
$assert(
    estab_navigation_active_key([
        'SCRIPT_NAME' => '/gateway/dispatch/site/4fach/vordrucke.php',
    ]) === 'forms'
        && estab_navigation_active_key([
            'SCRIPT_NAME' => '/gateway/dispatch/site/4fach/fuehrungsstelle.php',
        ]) === 'command-post'
        && estab_navigation_active_key([
            'REQUEST_URI' => '/gateway/dispatch/site/4fach/nachwea.php?nwalle',
        ]) === 'tracking'
        && estab_navigation_active_key([
            'SCRIPT_NAME' => '/gateway/dispatch/site/index.php',
        ]) === 'overview'
        && estab_navigation_active_key([
            'SCRIPT_NAME' => '/gateway/dispatch/site/handbuch/index.php',
        ]) === 'handbook',
    'base-path request resolution failed'
);
putenv('ESTAB_PUBLIC_URL=/');
putenv('ESTAB_BASE_PATH=');

$pathMappings = [
    '/' => 'overview',
    '/index.php' => 'overview',
    '/4fach/index.php' => 'messages',
    '/4fach/mainindex.php' => 'messages',
    '/4fach/vordrucke.php' => 'forms',
    '/4fach/nachwea.php?nwalle' => 'tracking',
    '/4fach/resetpic.php' => 'administration',
    '/4fueltg/ue_ltg.php' => 'message-overview',
    '/4fueltg/detail.php' => 'message-overview',
    '/stabetb/etb.php' => 'incident-log',
    '/fmtbb/tbb.php' => 'technical-log',
    '/stabinfo/index.php' => 'bos-info',
    '/4fadm/admin.php' => 'administration',
    '/handbuch/' => 'handbook',
    '/handbuch/index.php' => 'handbook',
];
foreach ($pathMappings as $path => $expectedKey) {
    $assert(
        estab_navigation_active_key(['REQUEST_URI' => $path]) === $expectedKey,
        'request path did not resolve to ' . $expectedKey . ': ' . $path
    );
}
$assert(
    estab_navigation_active_key([
        'SCRIPT_NAME' => '/stabetb/etb.php',
        'REQUEST_URI' => '/fmtbb/tbb.php',
    ]) === 'incident-log',
    'recognized SCRIPT_NAME did not take precedence over REQUEST_URI'
);
$assert(
    estab_navigation_active_key([
        'SCRIPT_NAME' => '/unknown.php',
        'REQUEST_URI' => '/fmtbb/tbb.php',
    ]) === 'technical-log',
    'recognized REQUEST_URI was not used as a resolver fallback'
);
foreach ([
    '/4fachish/index.php',
    '/stabetb-old/etb.php',
    '/other/index.php',
    '/doku/Handbuch_eStab.pdf',
    '//example.invalid/4fach/index.php',
    'https://example.invalid/4fach/index.php',
] as $unrecognizedPath) {
    $assert(
        estab_navigation_active_key(['REQUEST_URI' => $unrecognizedPath])
            === null,
        'resolver accepted an unrelated or external path: ' . $unrecognizedPath
    );
}

$authenticated = estab_navigation_markup(
    true,
    ['SCRIPT_NAME' => '/4fach/vordrucke.php'],
    false,
    false,
    $s1NavigationIdentity
);
$expectedLabels = array_merge(
    array_column(array_values(array_filter(
        $areas,
        static fn (array $area): bool => !in_array(
            $area['key'],
            ['message-overview', 'tracking'],
            true
        )
    )), 'label'),
    array_column($services, 'label')
);
$lastPosition = -1;
foreach ($expectedLabels as $label) {
    $position = strpos($authenticated, '>' . $label . '</span>');
    $assert(
        is_int($position) && $position > $lastPosition,
        'rendered navigation order changed at ' . $label
    );
    $lastPosition = is_int($position) ? $position : $lastPosition;
}
/*
 * Das Menue steht still: Alle elf Eintraege sind immer da. Ansteuerbar sind
 * fuer S1 neun davon; die beiden fremden Bereiche stehen sichtbar, aber ohne
 * Weg. Geprueft wird beides -- die Zahl der Eintraege und die Zahl der Wege.
 */
$assert(
    substr_count($authenticated, 'data-estab-navigation-item') === 11
        && substr_count($authenticated, 'data-estab-navigation-blocked') === 2
        && substr_count($authenticated, 'target="_top"') === 9
        && substr_count($authenticated, 'aria-current="page"') === 1
        && str_contains(
            $authenticated,
            'data-estab-navigation-key="forms"'
        )
        && str_contains(
            $authenticated,
            'data-estab-nav-key="forms"'
        )
        && !str_contains($authenticated, 'target="_blank"'),
    'full navigation markers, targets, or active state are incomplete'
);
$assert(
    str_contains($authenticated, 'href="/"')
        && !str_contains(
            $authenticated,
            'href="/4fach/nachwea.php?nwalle"'
        )
        && !str_contains($authenticated, 'href="/4fueltg/ue_ltg.php"')
        && str_contains($authenticated, 'href="/4fadm/admin.php"')
        && str_contains(
            $authenticated,
            'href="/handbuch/"'
        ),
    'authenticated navigation omitted canonical real targets'
);
$assert(
    preg_match(
        '/data-estab-navigation-key="forms"[^>]*>.*?'
            . 'aria-current="page"/s',
        $authenticated
    ) === 1,
    'active marker was not attached to the resolved item'
);
$s2Navigation = estab_navigation_markup(
    true,
    ['SCRIPT_NAME' => '/4fueltg/ue_ltg.php'],
    false,
    false,
    $s2NavigationIdentity
);
$ldfNavigation = estab_navigation_markup(
    true,
    ['SCRIPT_NAME' => '/4fach/nachwea.php'],
    false,
    false,
    $ldfNavigationIdentity
);
$assert(
    str_contains($s2Navigation, 'href="/4fueltg/ue_ltg.php"')
        && str_contains(
            $s2Navigation,
            'data-estab-navigation-duty-access="LAGE_DOKUMENTATION"'
        )
        && !str_contains(
            $s2Navigation,
            'href="/4fach/nachwea.php?nwalle"'
        )
        && str_contains(
            $ldfNavigation,
            'href="/4fach/nachwea.php?nwalle"'
        )
        && str_contains(
            $ldfNavigation,
            'data-estab-navigation-duty-access="FERNMELDE_NACHWEIS"'
        )
        && !str_contains(
            $ldfNavigation,
            'href="/4fueltg/ue_ltg.php"'
        ),
    'account-function navigation exposes a foreign privileged area'
);
$additionalNavigation = estab_navigation_markup(
    true,
    ['SCRIPT_NAME' => '/4fueltg/ue_ltg.php'],
    false,
    false,
    $s1WithS2NavigationIdentity
);
$assert(
    str_contains($additionalNavigation, 'href="/4fueltg/ue_ltg.php"')
        && !str_contains(
            $additionalNavigation,
            'href="/4fach/nachwea.php?nwalle"'
        ),
    'LOOSE navigation ignored an explicit S2 grant or exposed an ungranted area'
);
$strictWithoutHat = estab_navigation_markup(
    true,
    ['SCRIPT_NAME' => '/4fach/fuehrungsstelle.php'],
    false,
    false,
    [
        'funktion' => 'S2',
        'rolle' => 'Stab',
        'estab_permission_mode' => 'STRICT',
    ]
);
$strictWithHat = estab_navigation_markup(
    true,
    ['SCRIPT_NAME' => '/4fueltg/ue_ltg.php'],
    false,
    false,
    [
        'funktion' => 'S2',
        'rolle' => 'Stab',
        'estab_permission_mode' => 'STRICT',
        'duty_assignment_id' => 73,
    ]
);
// Ohne angetretenen Dienst bleiben ebenfalls alle Eintraege stehen; sechs
// von ihnen fuehren nirgendwohin, bis eine Funktion angenommen ist.
$assert(
    substr_count($strictWithoutHat, 'data-estab-navigation-item') === 11
        && substr_count($strictWithoutHat, 'data-estab-navigation-blocked') === 6
        && str_contains(
            $strictWithoutHat,
            'href="/4fach/fuehrungsstelle.php"'
        )
        && !str_contains($strictWithoutHat, 'href="/4fach/index.php"')
        && !str_contains($strictWithoutHat, 'href="/4fueltg/ue_ltg.php"')
        && str_contains($strictWithHat, 'href="/4fach/index.php"')
        && str_contains($strictWithHat, 'href="/4fueltg/ue_ltg.php"')
        && !str_contains(
            $strictWithHat,
            'href="/4fach/nachwea.php?nwalle"'
        ),
    'STRICT navigation does not require an explicitly selected duty assignment'
);
$accountNavigation = estab_navigation_markup(
    true,
    ['SCRIPT_NAME' => '/4fach/fuehrungsstelle.php'],
    false,
    false,
    $s1NavigationIdentity
);
$assert(
    substr_count(
        $accountNavigation,
        'data-estab-navigation-item'
    ) === 11
        && substr_count(
            $accountNavigation,
            'data-estab-navigation-blocked'
        ) === 2
        && str_contains(
            $accountNavigation,
            'href="/4fach/fuehrungsstelle.php"'
        )
        && str_contains($accountNavigation, 'href="/stabinfo/index.php"')
        && str_contains($accountNavigation, 'href="/4fadm/admin.php"')
        && str_contains($accountNavigation, 'href="/4fach/index.php"')
        && str_contains($accountNavigation, 'href="/stabetb/etb.php"')
        && str_contains($accountNavigation, 'href="/fmtbb/tbb.php"')
        && !str_contains($accountNavigation, 'href="/4fueltg/ue_ltg.php"')
        && !str_contains(
            $accountNavigation,
            'href="/4fach/nachwea.php?nwalle"'
        ),
    'fixed account identity does not expose its operational navigation'
);

$anonymous = estab_navigation_markup(false, ['SCRIPT_NAME' => '/index.php']);
$assert(
    substr_count($anonymous, 'data-estab-navigation-locked') === 7
        && substr_count($anonymous, 'Anmeldung erforderlich') === 7,
    'anonymous protected items do not expose their login requirement'
);
foreach ([
    'messages',
    'command-post',
    'message-overview',
    'forms',
    'incident-log',
    'technical-log',
    'tracking',
] as $destinationKey) {
    $assert(
        str_contains(
            $anonymous,
            'href="/4fach/index.php?login_flow=existing&amp;next='
                . $destinationKey . '"'
        ),
        'anonymous navigation lost destination key ' . $destinationKey
    );
}
$assert(
    str_contains($anonymous, 'href="/"')
        && str_contains($anonymous, 'href="/4fadm/admin.php"')
        && str_contains($anonymous, 'href="/handbuch/"')
        && str_contains($anonymous, 'href="/stabinfo/index.php"'),
    'anonymous navigation hid or redirected a public target'
);
$assert(
    substr_count($anonymous, 'aria-current="page"') === 1,
    'anonymous overview did not receive one active marker'
);
$unrecognized = estab_navigation_markup(
    true,
    ['SCRIPT_NAME' => '/unrelated.php'],
    false,
    false,
    $s1NavigationIdentity
);
$assert(
    !str_contains($unrecognized, 'aria-current='),
    'unrecognized request produced an active navigation item'
);

$compact = estab_navigation_markup(
    true,
    ['REQUEST_URI' => '/stabinfo/index.php'],
    true,
    false,
    $s1NavigationIdentity
);
$assert(
    str_contains($compact, 'data-estab-navigation')
        && str_contains($compact, 'data-estab-navigation-mode="compact"')
        && str_contains($compact, '<details')
        && str_contains($compact, '<summary>Bereich wechseln</summary>')
        && substr_count($compact, 'aria-current="page"') === 1,
    'compact details navigation contract is incomplete'
);
$sidebar = estab_navigation_markup(
    true,
    ['REQUEST_URI' => '/4fach/index.php'],
    true,
    true,
    $s1NavigationIdentity
);
$expectedSidebarLabels = array_merge(
    array_column(array_values(array_filter(
        $areas,
        static fn (array $area): bool => !in_array(
            $area['key'],
            ['message-overview', 'tracking'],
            true
        )
    )), 'short_label'),
    array_column($services, 'short_label')
);
$lastPosition = -1;
foreach ($expectedSidebarLabels as $label) {
    $position = strpos($sidebar, '>' . $label . '</span>');
    $assert(
        is_int($position) && $position > $lastPosition,
        'rendered sidebar navigation order changed at ' . $label
    );
    $lastPosition = is_int($position) ? $position : $lastPosition;
}
$assert(
    str_contains($sidebar, 'estab-navigation-sidebar')
        && str_contains($sidebar, 'data-estab-navigation-mode="sidebar"')
        && str_contains($sidebar, '<h2>Bereiche</h2>')
        && str_contains($sidebar, '<p>Arbeitsbereich wechseln</p>')
        && substr_count($sidebar, 'data-estab-navigation-item') === 11
        && substr_count($sidebar, 'data-estab-navigation-blocked') === 2
        && substr_count($sidebar, 'target="_top"') === 9
        && substr_count($sidebar, 'aria-current="page"') === 1
        && !str_contains($sidebar, '<details')
        && !str_contains($sidebar, '<summary'),
    'always-visible sidebar navigation contract is incomplete'
);
/*
 * Auch der gesperrte Eintrag traegt kurze Beschriftung und vollen Namen: Er
 * steht sichtbar im Menue, also muss ein Vorleseprogramm ihn benennen
 * koennen. Die Meldungsuebersicht ist fuer S1 gesperrt und trotzdem da.
 */
$assert(
    str_contains($sidebar, '>Nachrichten</span>')
        && str_contains($sidebar, '>Meldungen</span>')
        && str_contains($sidebar, '>ETB</span>')
        && str_contains($sidebar, '>TBB</span>')
        && str_contains(
            $sidebar,
            'aria-label="Nachrichtenvordruck" title="Nachrichtenvordruck"'
        )
        && str_contains($sidebar, 'aria-label="Meldungsübersicht"')
        && str_contains(
            $sidebar,
            'aria-label="Einsatztagebuch (ETB)"'
                . ' title="Einsatztagebuch (ETB)"'
        )
        && str_contains(
            $sidebar,
            'aria-label="Technisches Betriebsbuch (TBB)"'
                . ' title="Technisches Betriebsbuch (TBB)"'
        )
        && !str_contains($sidebar, '>Nachrichtenvordruck</span>')
        && !str_contains($sidebar, '>Meldungsübersicht</span>')
        && !str_contains($sidebar, '>Einsatztagebuch (ETB)</span>')
        && !str_contains($sidebar, '>Technisches Betriebsbuch (TBB)</span>'),
    'sidebar labels are not concise visually and complete accessibly'
);
$anonymousSidebar = estab_navigation_markup(
    false,
    ['REQUEST_URI' => '/4fach/vorgaben.php'],
    true,
    true
);
$assert(
    str_contains(
        $anonymousSidebar,
        'data-estab-navigation-mode="sidebar"'
    )
        && substr_count(
        $anonymousSidebar,
        'data-estab-navigation-locked'
        ) === 7
        && str_contains(
            $anonymousSidebar,
            'href="/4fach/index.php?login_flow=existing&amp;next=messages"'
        )
        && !str_contains($anonymousSidebar, '<details')
        && !str_contains($anonymousSidebar, '<summary'),
    'anonymous sidebar navigation is hidden, expandable, or exposes real protected targets'
);
$assert(
    str_contains(
        estab_navigation_markup(true, [], false),
        'data-estab-navigation-mode="full"'
    )
        && !str_contains(
            estab_navigation_markup(true, [], false),
            '<details'
        ),
    'full navigation unexpectedly uses compact disclosure markup'
);
$assertThrows(
    static fn (): string => estab_navigation_markup(
        true,
        [],
        false,
        true
    ),
    'sidebar navigation accepted non-compact session chrome'
);

$escaped = estab_navigation_item_markup([
    'key' => 'safe',
    'label' => '<script>alert("label")</script>',
    'path' => 'safe/page.php?first=1&second=%22quoted%22',
    'access' => 'public',
    'hint' => '<img src=x onerror=alert("hint")>',
], true, 'safe');
$assert(
    !str_contains($escaped, '<script>')
        && !str_contains($escaped, '<img')
        && str_contains(
            $escaped,
            '&lt;script&gt;alert(&quot;label&quot;)&lt;/script&gt;'
        )
        && str_contains(
            $escaped,
            '&lt;img src=x onerror=alert(&quot;hint&quot;)&gt;'
        )
        && str_contains(
            $escaped,
            'href="/safe/page.php?first=1&amp;second=%22quoted%22"'
        )
        && substr_count($escaped, 'aria-current="page"') === 1,
    'navigation item values were not escaped at the HTML boundary'
);
$escapedShortLabel = estab_navigation_item_markup([
    'key' => 'safe-short',
    'label' => 'Long label',
    'short_label' => '<strong>Short & "safe"</strong>',
    'path' => 'safe-short.php',
    'access' => 'public',
], true, null, true);
$assert(
    !str_contains($escapedShortLabel, '<strong>')
        && str_contains(
            $escapedShortLabel,
            '&lt;strong&gt;Short &amp; &quot;safe&quot;&lt;/strong&gt;'
        )
        && str_contains(
            $escapedShortLabel,
            'aria-label="Long label" title="Long label"'
        )
        && !str_contains($escapedShortLabel, '>Long label</span>'),
    'sidebar short label or its full accessible name is unsafe'
);
$assertThrows(
    static fn (): string => estab_navigation_item_markup([
        'key' => 'missing-short',
        'label' => 'Long label',
        'short_label' => '',
        'path' => 'missing-short.php',
        'access' => 'public',
    ], true, null, true),
    'empty sidebar short label accepted'
);
$assertThrows(
    static fn (): string => estab_navigation_item_markup([
        'key' => 'array-short',
        'label' => 'Long label',
        'short_label' => ['not', 'text'],
        'path' => 'array-short.php',
        'access' => 'public',
    ], true, null, true),
    'non-string sidebar short label accepted'
);

foreach ([
    'https://attacker.invalid/path',
    '//attacker.invalid/path',
    '../admin.php',
    'safe/../../admin.php',
    "safe.php\nLocation: https://attacker.invalid",
    'safe.php#javascript:alert(1)',
] as $unsafePath) {
    $assertThrows(
        static fn (): string => estab_navigation_item_markup([
            'key' => 'unsafe',
            'label' => 'Unsafe',
            'path' => $unsafePath,
            'access' => 'public',
        ], true, null),
        'unsafe external, traversal, or control path accepted: ' . $unsafePath
    );
}
$assertThrows(
    static fn (): string => estab_navigation_item_markup([
        'key' => 'bad" onclick="alert(1)',
        'label' => 'Bad key',
        'path' => 'safe.php',
        'access' => 'public',
    ], true, null),
    'malformed marker key accepted'
);
$assertThrows(
    static fn (): string => estab_navigation_item_markup([
        'key' => 'bad-access',
        'label' => 'Bad access',
        'path' => 'safe.php',
        'access' => 'external',
    ], true, null),
    'unknown access class accepted'
);
$assertThrows(
    static fn (): string => estab_navigation_login_url('administration'),
    'public service accepted as a post-login destination'
);
$assertThrows(
    static fn (): string => estab_navigation_login_url('https://attacker.invalid'),
    'external post-login destination accepted'
);
$assertThrows(
    static fn (): string => estab_navigation_group_markup([
        [
            'key' => 'duplicate',
            'label' => 'One',
            'path' => 'one.php',
            'access' => 'public',
        ],
        [
            'key' => 'duplicate',
            'label' => 'Two',
            'path' => 'two.php',
            'access' => 'public',
        ],
    ], true, null, 'areas'),
    'duplicate navigation keys accepted'
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

echo "navigation security: OK ({$assertions} assertions)\n";
