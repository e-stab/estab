<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/csrf.php';

$assertions = 0;
function csrf_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "CSRF security: FAIL: {$message}\n");
        exit(1);
    }
}

session_id('estab-csrf-test');
session_start();
$token = estab_csrf_token();
csrf_assert((bool) preg_match('/\A[a-f0-9]{64}\z/D', $token), 'strong token generated');
csrf_assert(estab_csrf_token() === $token, 'token stable within session');
csrf_assert(estab_csrf_is_valid($token), 'valid token accepted');
csrf_assert(!estab_csrf_is_valid(str_repeat('0', 64)), 'wrong token rejected');
csrf_assert(!estab_csrf_is_valid(['not-a-string']), 'non-string token rejected');
csrf_assert(str_contains(estab_csrf_field(), $token), 'form field contains token');

$postAccepted = true;
try {
    estab_csrf_require_post(['REQUEST_METHOD' => 'POST'], ['csrf_token' => $token]);
} catch (RuntimeException) {
    $postAccepted = false;
}
csrf_assert($postAccepted, 'valid POST accepted');

foreach (
    [
        [['REQUEST_METHOD' => 'GET'], ['csrf_token' => $token]],
        [['REQUEST_METHOD' => 'POST'], []],
    ] as [$server, $post]
) {
    $rejected = false;
    try {
        estab_csrf_require_post($server, $post);
    } catch (RuntimeException) {
        $rejected = true;
    }
    csrf_assert($rejected, 'unsafe request rejected');
}

session_destroy();
printf("CSRF security: OK (%d assertions)\n", $assertions);
