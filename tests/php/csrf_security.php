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

/*
 * Every productive controller using the shared guard must map a rejected
 * request before its generic failure handler. Keep discovery and the
 * allow-list together so a newly added controller cannot silently return 500
 * for an ordinary expired form.
 */
$root = dirname(__DIR__, 2);
$expectedControllers = [
    '4fach/activity.php',
    '4fach/anhang.php',
    '4fach/fuehrungsstelle.php',
    '4fach/katgoedt.php',
    '4fach/logout.php',
    '4fach/mainindex.php',
    '4fach/resetpic.php',
    '4fadm/export.php',
    '4fadm/fuehrungsstelle.php',
    '4fadm/incident_export.php',
    '4fadm/incidents.php',
    '4fadm/make_fkt.php',
    '4fadm/password_policy.php',
    '4fadm/set_number_after_crash.php',
    '4fadm/users.php',
];
$discoveredControllers = [];
foreach (['4fach', '4fadm', 'stabetb', 'fmtbb'] as $directory) {
    foreach (glob($root . '/' . $directory . '/*.php') ?: [] as $path) {
        $source = file_get_contents($path);
        csrf_assert(is_string($source), 'controller source unreadable: ' . $path);
        if (str_contains($source, 'estab_csrf_require_post')) {
            $discoveredControllers[] = substr($path, strlen($root) + 1);
        }
    }
}
sort($discoveredControllers, SORT_STRING);
csrf_assert(
    $discoveredControllers === $expectedControllers,
    'productive CSRF controller inventory changed without a response contract'
);

foreach ($expectedControllers as $relativePath) {
    $source = file_get_contents($root . '/' . $relativePath);
    csrf_assert(
        is_string($source),
        'productive CSRF controller unreadable: ' . $relativePath
    );
    $offset = 0;
    while (
        is_string($source)
        && ($guard = strpos($source, 'estab_csrf_require_post', $offset)) !== false
    ) {
        $handler = substr($source, $guard, 1200);
        $hasSpecificHandler =
            str_contains($source, 'catch (EstabCsrfException)');
        $hasLocalHandler =
            str_contains($handler, 'catch (Throwable')
            || str_contains($handler, 'catch (RuntimeException');
        $hasSafeResponse =
            str_contains($handler, 'http_response_code(403)')
            || str_contains($handler, 'http_response_code (403)')
            || str_contains($handler, 'http_response_code (400)')
            || str_contains($handler, 'estab_workflow_forbid')
            || (
                $hasSpecificHandler
                && (
                    str_contains($source, 'http_response_code(403)')
                    || str_contains($source, 'http_response_code (403)')
                )
            );
        csrf_assert(
            ($hasSpecificHandler || $hasLocalHandler) && $hasSafeResponse,
            'CSRF failure can reach a generic 500 handler in '
                . $relativePath
        );
        $offset = $guard + strlen('estab_csrf_require_post');
    }
}

$passwordPolicyController = file_get_contents(
    $root . '/4fadm/password_policy.php'
);
$passwordPolicyGuard = is_string($passwordPolicyController)
    ? strpos($passwordPolicyController, 'estab_csrf_require_post')
    : false;
$passwordPolicyHandler = is_string($passwordPolicyController)
    ? strpos($passwordPolicyController, 'catch (EstabCsrfException)')
    : false;
$passwordPolicyGenericHandler = is_string($passwordPolicyController)
    ? strpos($passwordPolicyController, 'catch (Throwable', $passwordPolicyHandler ?: 0)
    : false;
csrf_assert(
    is_string($passwordPolicyController)
        && $passwordPolicyGuard !== false
        && $passwordPolicyHandler !== false
        && $passwordPolicyGenericHandler !== false
        && $passwordPolicyGuard < $passwordPolicyHandler
        && $passwordPolicyHandler < $passwordPolicyGenericHandler
        && str_contains(
            substr(
                $passwordPolicyController,
                $passwordPolicyHandler,
                $passwordPolicyGenericHandler - $passwordPolicyHandler
            ),
            'http_response_code(403)'
        ),
    'password-policy CSRF rejection is not mapped to 403 before generic errors'
);

session_destroy();
printf("CSRF security: OK (%d assertions)\n", $assertions);
