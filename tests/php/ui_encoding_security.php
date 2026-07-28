<?php

declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__, 2);

/**
 * User-facing controllers plus the legacy renderers they include.
 *
 * Comments are intentionally ignored below. Some preserve historical encoding
 * damage, but they cannot reach a browser. String and inline-HTML tokens can.
 */
$uiFiles = [
    'index.php',
    'menue.inc.php',
    'app/navigation.php',
    'app/root_menu.php',
    'app/session_ui.php',
    'app/sidebar.php',
    '4fach/index.php',
    '4fach/mainindex.php',
    '4fach/vorgaben.php',
    '4fach/counter.php',
    '4fach/status.php',
    '4fach/anhang.php',
    '4fach/4fachform.php',
    '4fach/liste.php',
    '4fach/data_hndl.php',
    '4fach/tools.php',
    '4fach/vali_data.php',
    '4fach/katego.php',
    '4fach/protokoll.php',
    '4fach/db_operation.php',
    '4fach/upload_class.php',
    '4fach/button.php',
    '4fach/createbutton.php',
    '4fach/kategobutton.php',
    '4fach/vordrucke.php',
    '4fach/nachwea.php',
    '4fach/katgoedt.php',
    '4fach/info.php',
    '4fach/resetpic.php',
    '4fueltg/ue_ltg.php',
    'fmtbb/tbb.php',
    'stabetb/etb.php',
    'stabinfo/index.php',
    'stabinfo/l_index.php',
    'language/german/helptext.php',
    'language/german/hilfetext.php',
    '4fadm/admin.php',
    '4fadm/export.php',
    '4fadm/make_fkt.php',
    '4fadm/set_number_after_crash.php',
    '4fadm/system_status.php',
];

$mojibakePattern = '/(?:'
    . 'Ã|Â|â€|â€“|â€”|â€™|ï»¿|\x{FFFD}|[\x{0080}-\x{009F}]'
    . ')/u';
$visibleTokenIds = [
    T_CONSTANT_ENCAPSED_STRING,
    T_ENCAPSED_AND_WHITESPACE,
    T_INLINE_HTML,
];
$violations = [];
$sources = [];

foreach ($uiFiles as $relativePath) {
    $path = $root . '/' . $relativePath;
    $source = file_get_contents($path);
    $assert(is_string($source), $relativePath . ' is readable');
    $assert(
        preg_match('//u', $source) === 1,
        $relativePath . ' is valid UTF-8'
    );
    $sources[$relativePath] = $source;

    foreach (token_get_all($source) as $token) {
        if (
            !is_array($token)
            || !in_array($token[0], $visibleTokenIds, true)
            || preg_match($mojibakePattern, $token[1]) !== 1
        ) {
            continue;
        }
        $excerpt = preg_replace('/\s+/u', ' ', trim($token[1]));
        $violations[] = sprintf(
            '%s:%d: %s',
            $relativePath,
            $token[2],
            substr(is_string($excerpt) ? $excerpt : '', 0, 120)
        );
    }
}

$assert(
    $violations === [],
    "user-facing PHP tokens contain mojibake:\n" . implode("\n", $violations)
);
$assert(
    str_contains(
        $sources['4fach/anhang.php'],
        'Liste der verfügbaren Dateien'
    ),
    'attachment list uses the intended UTF-8 heading'
);
$assert(
    str_contains(
        $sources['4fach/4fachform.php'],
        'persönliche Ordner verwalten'
    ),
    'message form uses the intended UTF-8 folder label'
);
$assert(
    str_contains(
        $sources['4fach/mainindex.php'],
        'Mögliche Ursachen'
    ),
    'database error help uses the intended UTF-8 heading'
);
$assert(
    str_contains(
        $sources['4fach/liste.php'],
        'ohne Berücksichtigung der Kategorien'
    ),
    'category description uses the intended UTF-8 text'
);
$assert(
    str_contains(
        $sources['language/german/hilfetext.php'],
        'Sonstige Nachrichten an „W“-Fragen orientieren:'
    )
        && str_contains(
            $sources['language/german/hilfetext.php'],
            '<b>Wer – Wo – Was – Wann – Wie</b>'
        )
        && !str_contains(
            $sources['language/german/hilfetext.php'],
            '</<b>'
        ),
    'message-content help uses valid punctuation and markup'
);

printf("UI encoding security: OK (%d assertions)\n", $assertions);
