<?php

declare(strict_types=1);

/**
 * No shipped markup may carry an inline event-handler attribute.
 *
 * The policy sends `script-src 'self' 'nonce-...'` without `'unsafe-inline'`
 * and without `'unsafe-hashes'`. A nonce can only be written on a script
 * element -- it can never apply to an `onclick`/`onload` attribute, so every
 * such attribute is refused by the browser while the markup around it still
 * renders. The control simply stops working, and no test that inspects PHP
 * output would notice: the attribute is present in the HTML, it is only dead.
 *
 * The remedy is always the same: emit a script element with
 * estab_csp_script_attribute() and bind the behaviour to a data attribute.
 */

$root = dirname(__DIR__, 2);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Directories that never reach a browser. */
$skipped = [
    '.git', 'node_modules', 'vendor', 'tests', 'docs',
    'test-results', 'playwright-report',
];

$files = [];
$walk = static function (string $directory) use (&$walk, &$files, $skipped, $root): void {
    $entries = scandir($directory);
    if ($entries === false) {
        throw new RuntimeException('Cannot read ' . $directory);
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . '/' . $entry;
        if (is_dir($path)) {
            if (!in_array($entry, $skipped, true) && $directory !== $root . '/x') {
                $walk($path);
            }
            continue;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['php', 'html', 'htm'], true)) {
            $files[] = $path;
        }
    }
};
$walk($root);
sort($files);

$assert($files !== [], 'No shipped markup was found at all');

/**
 * The handlers a browser recognises as an attribute. Keeping the list explicit
 * avoids matching a PHP variable that merely ends in "on".
 */
$handlers = [
    'onabort', 'onblur', 'onchange', 'onclick', 'ondblclick', 'onerror',
    'onfocus', 'oninput', 'onkeydown', 'onkeypress', 'onkeyup', 'onload',
    'onmousedown', 'onmouseout', 'onmouseover', 'onmouseup', 'onreset',
    'onscroll', 'onselect', 'onsubmit', 'onunload', 'ontoggle',
];
$pattern = '~(?<![a-z0-9_$-])(' . implode('|', $handlers) . ')\s*=~i';

$offenders = [];
$scanned = 0;
foreach ($files as $file) {
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Cannot read ' . $file);
    }
    $scanned++;
    $line = 0;
    foreach (preg_split('~\R~', $contents) ?: [] as $text) {
        $line++;
        if (preg_match($pattern, $text) !== 1) {
            continue;
        }
        $offenders[] = substr($file, strlen($root) + 1) . ':' . $line
            . ' -> ' . trim($text);
    }
}

$assert($scanned > 100, 'The scan covered suspiciously few files');
$assert(
    $offenders === [],
    "Inline event-handler attributes are blocked by the policy and dead in a "
        . "browser:\n  " . implode("\n  ", $offenders)
);

printf(
    "csp inline handlers: OK (%d assertions, %d files scanned)\n",
    $assertions,
    $scanned
);
