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
        && str_contains($locked, 'href="/4fach/index.php"')
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
        && str_contains($unlocked, 'target="_blank"')
        && str_contains($unlocked, 'rel="noopener noreferrer"')
        && !str_contains($unlocked, 'Anmeldung erforderlich'),
    'authenticated protected menu card did not expose its real target safely'
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

echo "root menu security: OK ({$assertions} assertions)\n";
