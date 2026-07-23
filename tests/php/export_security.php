<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/export.php';

$assertions = 0;
function export_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "export security: FAIL: {$message}\n");
        exit(1);
    }
}

export_assert(estab_export_quote_identifier('nv_nachrichten') === '`nv_nachrichten`', 'normal identifier quoted');
export_assert(estab_export_quote_identifier('odd`name') === '`odd``name`', 'backtick escaped');
export_assert(estab_export_filename('nv_nachrichten') === 'nv_nachrichten.csv', 'portable table filename retained');
export_assert(estab_export_filename('../escape') === 'table-' . bin2hex('../escape') . '.csv', 'unsafe filename encoded');

foreach (['', str_repeat('a', 65), "bad\0name"] as $invalid) {
    $rejected = false;
    try {
        estab_export_quote_identifier($invalid);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    export_assert($rejected, 'invalid identifier rejected');
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'estab-export-test-' . bin2hex(random_bytes(6));
$run = estab_export_create_run_directory($base, new DateTimeImmutable('2026-07-22 12:00:00'));
export_assert(is_dir($run), 'private run directory created');
export_assert(str_starts_with(basename($run), 'estab-20260722-120000-'), 'timestamped run name');
rmdir($run);
rmdir($base);

printf("export security: OK (%d assertions)\n", $assertions);
