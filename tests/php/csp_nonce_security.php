<?php

declare(strict_types=1);

/**
 * No inline script may run without the nonce of its request.
 *
 * The policy used to admit `script-src 'unsafe-inline'`, which made the script
 * part of it decorative: an injected script element would have run like any
 * other. It now carries a per-request nonce, and that only holds as long as
 * every emitted script carries it -- a forgotten one stops working silently in
 * the browser, where no PHP test would see it.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/csp.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// The nonce is random, stable within a request, and long enough.
$first = estab_csp_nonce();
$assert(
    $first === estab_csp_nonce(),
    'The nonce changes within one request; the policy would never match'
);
$decoded = base64_decode($first, true);
$assert(
    is_string($decoded) && strlen($decoded) >= 16,
    'The nonce carries fewer than 128 bits and can be guessed'
);
$assert(
    preg_match('~\A[A-Za-z0-9+/]+={0,2}\z~D', $first) === 1,
    'The nonce is not base64 and would break the header'
);

$binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$script = 'require ' . var_export($root . '/app/csp.php', true)
    . '; echo estab_csp_nonce();';
$others = [];
for ($round = 0; $round < 3; $round++) {
    $output = [];
    exec(escapeshellarg($binary) . ' -r ' . escapeshellarg($script), $output);
    $others[] = trim(implode('', $output));
}
$assert(
    count(array_unique($others)) === count($others)
        && !in_array($first, $others, true),
    'Two requests received the same nonce'
);

// The policy names the nonce and no longer admits inline script.
$policy = estab_csp_policy($first);
$assert(
    str_contains($policy, "script-src 'self' 'nonce-" . $first . "'"),
    'The policy does not carry the nonce of this request'
);
$assert(
    !str_contains($policy, "script-src 'self' 'unsafe-inline'")
        && !preg_match("~script-src[^;]*'unsafe-inline'~", $policy),
    'The policy still admits inline script and protects nothing'
);
foreach ([
    "default-src 'self'",
    "base-uri 'self'",
    "object-src 'self'",
    "frame-ancestors 'self'",
    "form-action 'self'",
] as $directive) {
    $assert(
        str_contains($policy, $directive),
        'The policy lost the directive ' . $directive
    );
}

// Every inline script in the shipped surface carries the nonce.
$sources = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $entry) use ($root): bool {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $top = explode(DIRECTORY_SEPARATOR, $relative, 2)[0];
            return !in_array(
                $top,
                ['.git', 'docs', 'migration', 'tests', 'tmp', 'var', 'vendor'],
                true
            );
        }
    )
);
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $sources[substr($file->getPathname(), strlen($root) + 1)] =
            (string) file_get_contents($file->getPathname());
    }
}
$assert($sources !== [], 'No shipped source was found');

$inline = 0;
$offenders = [];
foreach ($sources as $relative => $source) {
    preg_match_all('~<script(?![^>]*\bsrc=)[^>]*>~', $source, $tags, PREG_OFFSET_CAPTURE);
    foreach ($tags[0] as $tag) {
        $inline++;
        if (!str_contains($tag[0], 'estab_csp_script_attribute')) {
            $offenders[] = $relative . ':'
                . (substr_count(substr($source, 0, (int) $tag[1]), "\n") + 1);
        }
    }
}
$assert(
    $inline >= 15,
    'Almost no inline script was found; the check would be empty'
);
$assert(
    $offenders === [],
    'Inline scripts without the nonce of their request: '
        . implode(', ', $offenders)
);

// Inline event attributes cannot carry a nonce at all and must be gone.
$handlers = [];
foreach ($sources as $relative => $source) {
    if (
        preg_match(
            '~\son(?:click|change|submit|input|load|keydown|keyup|focus|blur)\s*=\s*["\']~i',
            $source
        ) === 1
    ) {
        $handlers[] = $relative;
    }
}
$assert(
    $handlers === [],
    'Inline event attributes remain and no longer run: '
        . implode(', ', $handlers)
);

// Apache must not add a second policy to a PHP response: several policies are
// enforced together, and the static one forbids every script.
$vhost = file_get_contents($root . '/docker/apache/estab.conf');
$assert(is_string($vhost), 'Could not read the virtual host');
$vhost = (string) $vhost;
$assert(
    preg_match_all('~(?m)^\s*Header[^\n]*Content-Security-Policy~', $vhost) === 1,
    'The virtual host sets the policy more than once'
);
$assert(
    str_contains($vhost, 'Header always setifempty Content-Security-Policy')
        && str_contains($vhost, 'expr=-z %{resp:Content-Security-Policy}'),
    'Apache overwrites the per-request policy or appends a second one'
);
$assert(
    preg_match("~Content-Security-Policy[^\n]*script-src 'none'~", $vhost) === 1,
    'The static policy still admits script execution'
);

// The application must send its own policy before anything is written.
$bootstrap = file_get_contents($root . '/app/bootstrap.php');
$assert(
    is_string($bootstrap) && str_contains($bootstrap, 'estab_csp_send_header();'),
    'The runtime bootstrap does not send the policy'
);

printf(
    "csp nonce: OK (%d assertions, %d inline scripts)\n",
    $assertions,
    $inline
);
