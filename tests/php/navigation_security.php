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

$areas = estab_navigation_areas();
$services = estab_navigation_services();
$assert(
    array_column($areas, 'key') === [
        'overview',
        'messages',
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
        'Meldungsübersicht',
        'Vordrucke',
        'Einsatztagebuch (ETB)',
        'Technisches Betriebsbuch (TTB)',
        'Nachweisung',
        'BOS-Info',
    ],
    'operational navigation labels changed'
);
$assert(
    array_column($areas, 'short_label') === [
        'Übersicht',
        'Nachrichten',
        'Meldungen',
        'Vordrucke',
        'ETB',
        'TTB',
        'Nachweisung',
        'BOS-Info',
    ],
    'sidebar navigation labels changed'
);
$assert(
    array_column($areas, 'path') === [
        'index.php',
        '4fach/index.php',
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
            'doku/Handbuch_eStab.pdf',
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
        && estab_navigation_item_url($areas[6])
            === '/4fach/nachwea.php?nwalle',
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
        && estab_navigation_item_url($areas[4])
            === 'https://example.invalid/gateway/dispatch/site/stabetb/etb.php'
        && estab_navigation_login_url()
            === 'https://example.invalid/gateway/dispatch/site/4fach/index.php'
        && estab_navigation_login_url('incident-log')
            === 'https://example.invalid/gateway/dispatch/site/4fach/index.php'
                . '?next=incident-log',
    'navigation URLs did not preserve public URL and deployment base path'
);
$assert(
    estab_navigation_active_key([
        'SCRIPT_NAME' => '/gateway/dispatch/site/4fach/vordrucke.php',
    ]) === 'forms'
        && estab_navigation_active_key([
            'REQUEST_URI' => '/gateway/dispatch/site/4fach/nachwea.php?nwalle',
        ]) === 'tracking'
        && estab_navigation_active_key([
            'SCRIPT_NAME' => '/gateway/dispatch/site/index.php',
        ]) === 'overview',
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
    '/doku/Handbuch_eStab.pdf' => 'handbook',
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
    ['SCRIPT_NAME' => '/4fach/vordrucke.php']
);
$expectedLabels = array_merge(
    array_column($areas, 'label'),
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
$assert(
    substr_count($authenticated, 'data-estab-navigation-item') === 10
        && substr_count($authenticated, 'target="_top"') === 10
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
        && str_contains(
            $authenticated,
            'href="/4fach/nachwea.php?nwalle"'
        )
        && str_contains($authenticated, 'href="/4fadm/admin.php"')
        && str_contains(
            $authenticated,
            'href="/doku/Handbuch_eStab.pdf"'
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

$anonymous = estab_navigation_markup(false, ['SCRIPT_NAME' => '/index.php']);
$assert(
    substr_count($anonymous, 'data-estab-navigation-locked') === 6
        && substr_count($anonymous, 'Anmeldung erforderlich') === 6,
    'anonymous protected items do not expose their login requirement'
);
foreach ([
    'messages',
    'message-overview',
    'forms',
    'incident-log',
    'technical-log',
    'tracking',
] as $destinationKey) {
    $assert(
        str_contains(
            $anonymous,
            'href="/4fach/index.php?next=' . $destinationKey . '"'
        ),
        'anonymous navigation lost destination key ' . $destinationKey
    );
}
$assert(
    str_contains($anonymous, 'href="/"')
        && str_contains($anonymous, 'href="/4fadm/admin.php"')
        && str_contains($anonymous, 'href="/doku/Handbuch_eStab.pdf"')
        && str_contains($anonymous, 'href="/stabinfo/index.php"'),
    'anonymous navigation hid or redirected a public target'
);
$assert(
    substr_count($anonymous, 'aria-current="page"') === 1,
    'anonymous overview did not receive one active marker'
);
$unrecognized = estab_navigation_markup(
    true,
    ['SCRIPT_NAME' => '/unrelated.php']
);
$assert(
    !str_contains($unrecognized, 'aria-current='),
    'unrecognized request produced an active navigation item'
);

$compact = estab_navigation_markup(
    true,
    ['REQUEST_URI' => '/stabinfo/index.php'],
    true
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
    true
);
$expectedSidebarLabels = array_merge(
    array_column($areas, 'short_label'),
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
        && substr_count($sidebar, 'data-estab-navigation-item') === 10
        && substr_count($sidebar, 'target="_top"') === 10
        && substr_count($sidebar, 'aria-current="page"') === 1
        && !str_contains($sidebar, '<details')
        && !str_contains($sidebar, '<summary'),
    'always-visible sidebar navigation contract is incomplete'
);
$assert(
    str_contains($sidebar, '>Nachrichten</span>')
        && str_contains($sidebar, '>Meldungen</span>')
        && str_contains($sidebar, '>ETB</span>')
        && str_contains($sidebar, '>TTB</span>')
        && str_contains(
            $sidebar,
            'aria-label="Nachrichtenvordruck" title="Nachrichtenvordruck"'
        )
        && str_contains(
            $sidebar,
            'aria-label="Meldungsübersicht" title="Meldungsübersicht"'
        )
        && str_contains(
            $sidebar,
            'aria-label="Einsatztagebuch (ETB)"'
                . ' title="Einsatztagebuch (ETB)"'
        )
        && str_contains(
            $sidebar,
            'aria-label="Technisches Betriebsbuch (TTB)"'
                . ' title="Technisches Betriebsbuch (TTB)"'
        )
        && !str_contains($sidebar, '>Nachrichtenvordruck</span>')
        && !str_contains($sidebar, '>Meldungsübersicht</span>')
        && !str_contains($sidebar, '>Einsatztagebuch (ETB)</span>')
        && !str_contains($sidebar, '>Technisches Betriebsbuch (TTB)</span>'),
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
        ) === 6
        && str_contains(
            $anonymousSidebar,
            'href="/4fach/index.php?next=messages"'
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
