<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/image_button.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertRejected = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (InvalidArgumentException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$icon = estab_image_validate_button_request([
    'type' => 'icon',
    'status' => 'EIN',
    'text' => '25',
    'bg' => 'lighterblue',
]);
$assert(
    $icon === [
        'type' => 'icon',
        'status' => 'EIN',
        'text' => '25',
        'bg' => 'lighterblue',
        'textcol' => 'black',
        'bordercol' => 'black',
        'font_size' => 5,
    ],
    'active icon request did not retain its compatible defaults'
);

$push = estab_image_validate_button_request([
    'type' => 'push',
    'status' => 'AUS',
    'text' => 'erledigt',
    'textpos' => 'buttom',
]);
$assert($push['textpos'] === 'buttom', 'historical push-button position was rejected');

$menu = estab_image_validate_button_request([
    'type' => 'menue',
    'm_text' => '<=zuordnen',
    'm_fs' => '10',
    'm_form' => 'rund',
    'width' => '99',
    'bg' => 'mlightblue',
]);
$assert(
    $menu['m_text'] === '<=zuordnen'
        && $menu['m_fs'] === 10
        && $menu['width'] === 99,
    'active menu-button request was not normalised'
);
$unicodeMenu = estab_image_validate_button_request([
    'type' => 'menue',
    'm_text' => 'Anhänge',
    'm_fs' => '10',
    'm_form' => 'spitz',
]);
$assert($unicodeMenu['m_text'] === 'Anhänge', 'valid UTF-8 menu label was rejected');

$invalidButtonRequests = [
    ['type' => 'radio', 'switches' => '1,A;0,B'],
    ['type' => 'tumbler', 'ontext' => 'Ein', 'offtext' => 'Aus'],
    ['status' => 'EIN', 'text' => '1'],
    ['type' => 'icon', 'status' => 'AN', 'text' => '1'],
    ['type' => 'icon', 'status' => 'EIN', 'text' => ['1']],
    ['type' => 'icon', 'status' => 'EIN', 'text' => "eins\nzwei"],
    ['type' => 'icon', 'status' => 'EIN', 'text' => "\xC3\x28"],
    ['type' => 'icon', 'status' => 'EIN', 'text' => '1', 'font_size' => '6'],
    ['type' => 'icon', 'status' => 'EIN', 'text' => '1', 'unknown' => 'value'],
    ['type' => 'menue', 'm_text' => 'Menü', 'm_fs' => '17', 'm_form' => 'rund'],
    ['type' => 'menue', 'm_text' => 'Menü', 'm_fs' => '10', 'm_form' => 'eckig'],
    ['type' => 'menue', 'm_text' => 'Menü', 'm_fs' => '10', 'm_form' => 'rund', 'width' => '321'],
    ['type' => 'menue', 'm_text' => str_repeat('x', 49), 'm_fs' => '10', 'm_form' => 'rund'],
    ['type' => 'menue', 'm_text' => 'Menü', 'm_fs' => '10', 'm_form' => 'rund', 'bg' => 'transparent'],
];
foreach ($invalidButtonRequests as $index => $request) {
    $assertRejected(
        static fn () => estab_image_validate_button_request($request),
        'unsafe button request accepted at index ' . $index
    );
}

$label = estab_image_validate_label_request(['icontext' => 'ALLE', 'color' => 'lightblue']);
$assert(
    $label === ['icontext' => 'ALLE', 'color' => 'lightblue'],
    'active category-label request was not retained'
);
foreach ([
    'blue',
    'red',
    'yellow',
    'green',
    'lightblue',
    'lightred',
    'lightyellow',
    'lightgreen',
] as $colour) {
    $validated = estab_image_validate_label_request([
        'icontext' => 'Kategorie',
        'color' => $colour,
    ]);
    $assert($validated['color'] === $colour, 'active label colour was rejected: ' . $colour);
}
foreach ([
    ['icontext' => 'Kategorie'],
    ['color' => 'blue'],
    ['icontext' => ['Kategorie'], 'color' => 'blue'],
    ['icontext' => str_repeat('x', 49), 'color' => 'blue'],
    ['icontext' => 'Kategorie', 'color' => 'purple'],
    ['icontext' => 'Kategorie', 'color' => 'blue', 'width' => '999'],
] as $index => $request) {
    $assertRejected(
        static fn () => estab_image_validate_label_request($request),
        'unsafe category-label request accepted at index ' . $index
    );
}

$root = dirname(__DIR__, 2);
$apache = (string) file_get_contents($root . '/docker/apache/estab.conf');
$info = (string) file_get_contents($root . '/4fach/info.php');
$status = (string) file_get_contents($root . '/4fach/status.php');
$help = (string) file_get_contents($root . '/language/german/helptext.php');
$tools = (string) file_get_contents($root . '/4fach/tools.php');
$toolsNew = (string) file_get_contents($root . '/4fach/tools_neu.php');
$imageRenderer = (string) file_get_contents($root . '/app/image_button.php');
$rootMenu = (string) file_get_contents($root . '/menue.inc.php');
$httpSmoke = (string) file_get_contents($root . '/tests/integration/http_smoke.sh');
$httpSurface = (string) file_get_contents($root . '/tests/integration/http_surface_http.sh');
$logbookHttp = (string) file_get_contents($root . '/tests/integration/logbooks_http.sh');

$locationPatterns = [];
if (
    preg_match_all(
        '~<LocationMatch "([^"]+)">\s*(?:(?!</LocationMatch>).)*Require all denied(?:(?!</LocationMatch>).)*</LocationMatch>~s',
        $apache,
        $matches
    ) !== false
) {
    $locationPatterns = $matches[1];
}
$deniedByLocationPattern = static function (string $path) use ($locationPatterns): bool {
    foreach ($locationPatterns as $pattern) {
        if (preg_match('~' . str_replace('~', '\\~', $pattern) . '~', $path) === 1) {
            return true;
        }
    }
    return false;
};

$internalPaths = [
    '/4fach/4fachform.php',
    '/4fach/color.inc.php',
    '/4fach/data_hndl.php',
    '/4fach/data_hndl_gespr_A.php',
    '/4fach/data_hndl_gespr_E.php',
    '/4fach/db_operation.php',
    '/4fach/dummy.php',
    '/4fach/katego.php',
    '/4fach/liste.php',
    '/4fach/logoff.php',
    '/4fach/menue.php',
    '/4fach/navi.php',
    '/4fach/protokoll.php',
    '/4fach/stab_status.php',
    '/4fach/tools.php',
    '/4fach/tools.php/path-info',
    '/4fach/tools_neu.php',
    '/4fach/topmenue.php',
    '/4fach/upload_class.php',
    '/4fach/vali_data.php',
    '/4fbak/backup.php',
    '/4fbak/backup_pdf.php',
    '/4fbak/logo.png',
    '/language/german/form.php',
    '/language/german/helptextold.php',
    '/language/german/hilfetext.php',
    '/language/german/mennu.php',
    '/4fadm/usermgr.php',
    '/4fadm/usermgr.php/path-info',
    '/4fadm/db_generator.php',
];
foreach ($internalPaths as $path) {
    $assert($deniedByLocationPattern($path), 'Apache location rules do not deny ' . $path);
}
$assert(
    str_contains(
        $apache,
        '<Location "/ubltg/js_windowtwar.php">' . "\n        Require all denied"
    ),
    'Apache still exposes the window demonstration endpoint'
);
$assert(
    str_contains($apache, '<FilesMatch "(?i)\.inc\.php$">')
        && $deniedByLocationPattern('/4fadm/admmenue.inc.php')
        && $deniedByLocationPattern('/4fadm/admmenue.inc.php/path-info'),
    'Apache does not block direct *.inc.php requests'
);
$assert(
    !str_contains($apache, "'unsafe-eval'")
        && str_contains($apache, "script-src 'self' 'unsafe-inline'"),
    'Content Security Policy still permits eval or lost the documented inline compatibility'
);
foreach ([$tools, $toolsNew] as $frameHelper) {
    $assert(
        !str_contains($frameHelper, 'eval(')
            && str_contains(
                $frameHelper,
                'for(var i=0;i+1<arguments.length;i+=2)'
            )
            && str_contains(
                $frameHelper,
                'var frame=parent[arguments[i+1]];'
            )
            && str_contains(
                $frameHelper,
                'if(frame){frame.location.href=arguments[i];}'
            )
            && !str_contains($frameHelper, 'var frame3'),
        'frame navigation does not safely iterate existing target pairs'
    );
}
$assert(
    !str_contains($imageRenderer, 'imagedestroy(')
        && !preg_match(
            '/image(?:filled)?polygon\s*\([^,]+,[^,]+,\s*[0-9]+\s*,/',
            $imageRenderer
        ),
    'image renderer still calls a PHP 8.5-deprecated GD signature'
);

$integrationCoverage = $httpSurface . $httpSmoke . $logbookHttp;
foreach ([
    './4fach/index.php' => '/4fach/index.php',
    './4fach/vordrucke.php' => '/4fach/vordrucke.php',
    './4fueltg/ue_ltg.php' => '/4fueltg/ue_ltg.php',
    './stabinfo/index.php' => '/stabinfo/index.php',
    './4fadm/admin.php' => '/4fadm/admin.php',
    './stabetb/etb.php' => '/stabetb/etb.php',
    './fmtbb/tbb.php' => '/fmtbb/tbb.php',
    './4fach/nachwea.php?nwalle' => '/4fach/nachwea.php',
    './doku/Handbuch_eStab.pdf' => '/doku/Handbuch_eStab.pdf',
] as $menuLink => $testNeedle) {
    $assert(str_contains($rootMenu, $menuLink), 'expected visible root-menu link is absent: ' . $menuLink);
    $assert(
        str_contains($integrationCoverage, $testNeedle),
        'visible root-menu target lacks HTTP coverage: ' . $menuLink
    );
}
foreach ([
    'Buchstabier.html',
    'Kartendatum.html',
    'IuK-InfoPack.html',
    'Orgas.html',
    'FF-Rufnamenschema.html',
    'DRK%20Rufnamenschema.html',
    'THWFuRNR.html',
    'ELStab.jpg',
    'Warendorf%20L4112%20Kartendatum%20ED50.png',
    'Warendorf%20L4112%20Kartendatum%20WGS84.png',
] as $informationResource) {
    $assert(
        str_contains($httpSurface, $informationResource),
        'linked stabinfo resource lacks HTTP coverage: ' . $informationResource
    );
}

$thwInformation = (string) file_get_contents($root . '/stabinfo/THWFuRNR.html');
$organisationInformation = (string) file_get_contents($root . '/stabinfo/Orgas.html');
$assert(
    substr_count(
        $thwInformation,
        'aria-label="kleiner oder gleich">&le;</span>'
    ) === 12,
    'THW information did not retain all twelve local <= comparisons'
);
$assert(
    !str_contains($organisationInformation, '040.gif'),
    'organisation information still embeds the meaningless remote marker'
);
$informationFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $root . '/stabinfo',
        FilesystemIterator::SKIP_DOTS
    )
);
foreach ($informationFiles as $informationFile) {
    if (!$informationFile->isFile()) {
        continue;
    }
    $informationContents = (string) file_get_contents($informationFile->getPathname());
    $assert(
        stripos($informationContents, 'http://') === false,
        'stabinfo still contains an insecure remote dependency: '
            . $informationFile->getFilename()
    );
}

foreach ([
    '/4fach/button.php',
    '/4fach/createbutton.php',
    '/4fach/kategobutton.php',
    '/4fach/info.php',
    '/4fach/status.php',
    '/language/german/helptext.php',
] as $publicPath) {
    $assert(
        !$deniedByLocationPattern($publicPath),
        'supported public helper accidentally matches a deny rule: ' . $publicPath
    );
}

$assert(
    str_contains($info, 'htmlspecialchars(')
        && str_contains($info, "strlen(\$value) > \$maximumBytes")
        && str_contains($info, "['sub', 'info']"),
    'info.php lacks bounded HTML output handling'
);
$assert(
    str_contains($help, "array_key_exists(\$errorKind, \$Infotext)")
        && str_contains($help, "require __DIR__ . '/hilfetext.php'")
        && str_contains($help, 'htmlspecialchars($title'),
    'helptext.php lacks an enumerated lookup and escaped title'
);
$identityBoundary = strpos($status, 'estab_auth_session_identity($_SESSION)');
$legacyTools = strpos($status, "require_once __DIR__ . '/tools.php'");
$assert(
    $identityBoundary !== false
        && $legacyTools !== false
        && $identityBoundary < $legacyTools
        && str_contains($status, 'Status erst nach Anmeldung verfügbar.'),
    'status.php loads sensitive runtime helpers before its anonymous boundary'
);

echo "HTTP surface security: OK ({$assertions} assertions)\n";
