<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/root_menu.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

putenv('ESTAB_PUBLIC_URL=/');
putenv('ESTAB_BASE_PATH=');

$protected = [
    'text' => 'Geschützt<BR>(S2)',
    'info' => 'Nur nach Anmeldung &amp; Freigabe',
    'pic' => './icon.png',
    'link' => './protected.php',
    'visible' => true,
    'access' => 'application',
];
$locked = estab_root_menu_item_markup($protected, false);
$assert(
    substr_count($locked, '<a ') === 1
        && str_contains(
            $locked,
            'href="/4fach/index.php?login_flow=existing"'
        )
        && !str_contains($locked, 'href="./protected.php"')
        && !str_contains($locked, 'target="_blank"')
        && str_contains($locked, 'Anmeldung erforderlich'),
    'anonymous protected menu card does not guide users to login'
);
$assert(
    str_contains($locked, 'Geschützt<br>' . "\n" . '(S2)')
        && str_contains($locked, 'Nur nach Anmeldung &amp; Freigabe'),
    'legacy menu text was not converted into safe visible copy'
);

$unlocked = estab_root_menu_item_markup($protected, true);
$assert(
    substr_count($unlocked, '<a ') === 1
        && str_contains($unlocked, 'href="./protected.php"')
        && !str_contains($unlocked, 'target=')
        && !str_contains($unlocked, 'Anmeldung erforderlich'),
    'authenticated protected menu card did not expose its real same-tab target safely'
);

$keyedProtected = [
    'text' => 'Einsatztagebuch (ETB)',
    'info' => 'Geschützter Bereich',
    'pic' => './icon.png',
    'link' => './stabetb/etb.php',
    'navigation_key' => 'incident-log',
    'visible' => true,
    'access' => 'application',
];
$keyedLocked = estab_root_menu_item_markup($keyedProtected, false);
$assert(
    str_contains(
        $keyedLocked,
        'href="/4fach/index.php?login_flow=existing&amp;next=incident-log"'
    )
        && str_contains(
            $keyedLocked,
            'data-estab-nav-key="incident-log"'
        )
        && !str_contains($keyedLocked, 'href="./stabetb/etb.php"'),
    'protected root card did not retain its validated post-login destination'
);

$administration = estab_root_menu_item_markup([
    'text' => 'Administration',
    'info' => 'Technischer Zugang',
    'pic' => './admin.png',
    'link' => './admin.php',
    'visible' => true,
    'access' => 'administration',
], false);
$assert(
    str_contains($administration, 'Separater Administrationszugang')
        && str_contains($administration, 'href="./admin.php"'),
    'administration is not visibly separated from application login'
);

$handbook = estab_root_menu_item_markup([
    'text' => 'Handbuch',
    'info' => 'Aktuelles, durchsuchbares Web-Handbuch',
    'pic' => './handbook.png',
    'link' => './handbuch/',
    'navigation_key' => 'handbook',
    'visible' => true,
    'access' => 'public',
], false);
$assert(
    str_contains($handbook, 'href="./handbuch/"')
        && str_contains($handbook, 'data-estab-nav-key="handbook"')
        && !str_contains($handbook, 'Anmeldung erforderlich')
        && !str_contains($handbook, 'target='),
    'public web handbook card is not directly reachable in the same tab'
);

$escaped = estab_root_menu_item_markup([
    'text' => '<script>alert(1)</script>',
    'info' => '" onclick="alert(2)',
    'pic' => 'x&quot; onerror=&quot;alert(3)',
    'link' => './safe',
    'visible' => true,
    'access' => 'public',
], false);
$assert(
    !str_contains($escaped, '<script>')
        && !str_contains($escaped, 'title="" onclick=')
        && !str_contains($escaped, 'src="x" onerror=')
        && str_contains($escaped, 'title="&quot; onclick=&quot;alert(2)"')
        && str_contains($escaped, 'src="x&amp;quot; onerror=&amp;quot;alert(3)"'),
    'root menu rendered executable configured markup'
);

$assert(
    estab_root_menu_item_markup(
        array_replace($protected, ['visible' => false]),
        false
    ) === '',
    'hidden menu card was rendered'
);
$assert(
    substr_count(
        estab_root_menu_markup([$protected, $protected], false),
        'class="estab-menu-card '
    ) === 2,
    'menu grid omitted visible cards'
);

$orderedGrid = estab_root_menu_markup([
    3 => array_replace($protected, ['text' => 'Dritter Eintrag']),
    1 => array_replace($protected, ['text' => 'Erster Eintrag']),
    2 => array_replace($protected, ['text' => 'Zweiter Eintrag']),
], false);
$firstPosition = strpos($orderedGrid, 'Erster Eintrag');
$secondPosition = strpos($orderedGrid, 'Zweiter Eintrag');
$thirdPosition = strpos($orderedGrid, 'Dritter Eintrag');
$assert(
    is_int($firstPosition)
        && is_int($secondPosition)
        && is_int($thirdPosition)
        && $firstPosition < $secondPosition
        && $secondPosition < $thirdPosition,
    'menu grid did not preserve the historical numeric menu order'
);

$invalidAccessRejected = false;
try {
    estab_root_menu_item_markup(
        array_replace($protected, ['access' => 'unknown']),
        false
    );
} catch (InvalidArgumentException) {
    $invalidAccessRejected = true;
}
$assert($invalidAccessRejected, 'unknown menu access class accepted');

$invalidNavigationKeyRejected = false;
try {
    estab_root_menu_item_markup(
        array_replace($keyedProtected, ['navigation_key' => 'administration']),
        false
    );
} catch (InvalidArgumentException) {
    $invalidNavigationKeyRejected = true;
}
$assert(
    $invalidNavigationKeyRejected,
    'root menu accepted a navigation key that does not match its target'
);

$menuConfig = (string) file_get_contents(
    dirname(__DIR__, 2) . '/menue.inc.php'
);
$expectedOperationalCards = [
    'messages',
    'command-post',
    'message-overview',
    'forms',
    'incident-log',
    'technical-log',
    'tracking',
    'bos-info',
];
$configuredOperationalCards = [];
if (preg_match_all(
    '/\\$menue\\[(\\d+)\\]\\["navigation_key"\\]\\s*=\\s*"([^"]+)"/',
    $menuConfig,
    $configuredCardMatches,
    PREG_SET_ORDER
) !== false) {
    foreach ($configuredCardMatches as $configuredCardMatch) {
        $configuredOperationalCards[(int) $configuredCardMatch[1]]
            = $configuredCardMatch[2];
    }
}
ksort($configuredOperationalCards, SORT_NUMERIC);
$assert(
    array_values($configuredOperationalCards) === $expectedOperationalCards,
    'root menu cards diverge from the canonical operational order'
);
$configuredServiceCards = [];
if (preg_match_all(
    '/\\$zusatz_menue\\[(\\d+)\\]\\["navigation_key"\\]\\s*=\\s*"([^"]+)"/',
    $menuConfig,
    $configuredServiceMatches,
    PREG_SET_ORDER
) !== false) {
    foreach ($configuredServiceMatches as $configuredServiceMatch) {
        $configuredServiceCards[(int) $configuredServiceMatch[1]]
            = $configuredServiceMatch[2];
    }
}
ksort($configuredServiceCards, SORT_NUMERIC);
$assert(
    array_values($configuredServiceCards) === [
        'administration',
        'handbook',
    ],
    'root menu services diverge from the canonical service order'
);
$assert(
    str_contains(
        $menuConfig,
        '$menue[2]["link"] = "./4fach/fuehrungsstelle.php";'
    )
        && str_contains(
            $menuConfig,
            '$menue[2]["access"] = "application";'
        )
        && str_contains(
            $menuConfig,
            '$menue[2]["visible"] = true ;'
        ),
    'root menu does not expose the protected command-post card'
);
$assert(
    str_contains(
        $menuConfig,
        '$zusatz_menue[2]["link"] = "./handbuch/";'
    )
        && str_contains(
            $menuConfig,
            '$zusatz_menue[2]["access"] = "public";'
        )
        && str_contains(
            $menuConfig,
            'Aktuelles, durchsuchbares Web-Handbuch'
        )
        && !str_contains($menuConfig, './doku/Handbuch_eStab.pdf'),
    'root menu does not expose the current public web handbook'
);

echo "root menu security: OK ({$assertions} assertions)\n";
