<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/logbook.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$validTitle = estab_logbook_validate_title([
    'einsatz' => 'Hochwasser Süd',
    'ort' => 'Kreisgebiet & Innenstadt',
]);
$assert($validTitle['valid'] === true, 'valid Unicode title rejected');
$assert(
    estab_logbook_validate_title(['einsatz' => '', 'ort' => 'Test'])['valid'] === false,
    'empty operation title accepted'
);
$assert(
    estab_logbook_validate_title([
        'einsatz' => str_repeat('x', ESTAB_LOGBOOK_TITLE_MAX_LENGTH + 1),
        'ort' => 'Test',
    ])['valid'] === false,
    'overlong operation title accepted'
);
$assert(
    estab_logbook_validate_title(['einsatz' => "Test\nInjected", 'ort' => 'Test'])['valid'] === false,
    'control character in single-line title accepted'
);

$validEntry = estab_logbook_validate_entry([
    'event' => "Lageänderung\nzweite Zeile <script>",
    'comment' => 'Rückmeldung & Prüfung',
]);
$assert($validEntry['valid'] === true, 'valid multiline entry rejected');
$assert(
    estab_logbook_validate_entry(['event' => '', 'comment' => ''])[ 'valid'] === false,
    'empty event accepted'
);
$assert(
    estab_logbook_validate_entry([
        'event' => str_repeat('x', ESTAB_LOGBOOK_TEXT_MAX_LENGTH + 1),
        'comment' => '',
    ])['valid'] === false,
    'overlong event accepted'
);
$assert(
    estab_logbook_validate_entry(['event' => ['nested'], 'comment' => ''])['valid'] === false,
    'non-scalar event accepted'
);
$assert(
    estab_auth_html('<script>alert("x")</script>')
        === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
    'logbook HTML boundary does not escape active markup'
);

$root = dirname(__DIR__, 2);
$helper = file_get_contents($root . '/app/logbook.php');
$etb = file_get_contents($root . '/stabetb/etb.php');
$tbb = file_get_contents($root . '/fmtbb/tbb.php');
if (!is_string($helper) || !is_string($etb) || !is_string($tbb)) {
    throw new RuntimeException('Could not read logbook source files');
}

$assert(
    substr_count($helper, '$connection->prepare(') >= 3
        && substr_count($helper, '->bind_param(') >= 3,
    'logbook database values are not consistently parameterized'
);
$assert(
    str_contains($helper, "WHERE `mtx_rc2` IN ('t', '1')"),
    'ETB red-copy lookup does not support current and historical flags'
);
$assert(
    str_contains($helper, 'ESTAB_LOGBOOK_TITLE_MAX_LENGTH = 255')
        && str_contains($helper, 'ESTAB_LOGBOOK_TEXT_MAX_LENGTH = 10000'),
    'server-side logbook length limits are missing'
);
$assert(
    str_contains($helper, 'estab_auth_table($table)')
        && str_contains($helper, "in_array(\$kind, ['etb', 'tbb'], true)"),
    'dynamic table or column identifiers are not allowlisted'
);
$assert(
    str_contains($helper, 'estab_csrf_require_post($server, $post)'),
    'shared logbook CSRF gate is missing'
);

foreach (['ETB' => $etb, 'TBB' => $tbb] as $name => $source) {
    $normalizedMarkupSource = str_replace('\\"', '"', $source);
    $assert(
        preg_match(
            '/<form\b[^>]*\bmethod="post"/i',
            $normalizedMarkupSource
        ) === 1
            && str_contains($source, 'estab_csrf_field ()')
            && str_contains($source, 'estab_logbook_require_csrf ($_SERVER, $_POST)'),
        "{$name} writes are not POST-only and CSRF-protected"
    );
    $assert(
        str_contains($source, 'estab_auth_html (')
            && str_contains($source, 'maxlength=\"10000\"'),
        "{$name} output escaping or browser-side length boundary is missing"
    );
    $assert(
        !str_contains($source, 'query_table_iu')
            && !str_contains($source, 'speichen_' . strtolower($name) . '_eintrag ($_GET)'),
        "{$name} still exposes a legacy interpolated GET write path"
    );
    $assert(
        str_contains($source, 'estab_logbook_abort (403'),
        "{$name} role/CSRF failures do not return HTTP 403"
    );
    $assert(
        str_contains($source, 'estab_auth_require_session ($_SESSION)')
            && str_contains($source, 'if ($requestMethod === "POST")')
            && str_contains($source, 'if (!$berechtigt)'),
        "{$name} authenticated read/write boundary is missing"
    );
}

$assert(
    str_contains($etb, 'estab_logbook_redcopy_function (')
        && !str_contains($etb, '$readauth')
        && !str_contains($etb, 'Keine Berechtigung für das Einsatztagebuch'),
    'ETB must be readable by every authenticated user and writable only by red-copy'
);
$assert(
    str_contains($tbb, 'strcasecmp ($identity ["funktion"], "A/W")')
        && str_contains($tbb, 'strcasecmp ($identity ["rolle"], "Fernmelder")')
        && !str_contains($tbb, '$readonly')
        && !str_contains($tbb, 'Keine Berechtigung für das technische Betriebsbuch'),
    'TBB must be readable by every authenticated user and writable only by A/W Fernmelder'
);

echo "logbook security: OK ({$assertions} assertions)\n";
