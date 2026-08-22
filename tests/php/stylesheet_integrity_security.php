<?php

declare(strict_types=1);

/**
 * The stylesheets must parse.
 *
 * No check in this suite ever looked at the CSS as a whole: a lost selector
 * line leaves its declarations orphaned, the browser drops them silently, and
 * every rule that follows shifts into the wrong block. That is invisible in a
 * diff and invisible in every behavioural test. This one reads the sheets the
 * way a parser does.
 */

$root = dirname(__DIR__, 2);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$sheets = ['estab-ui.css', 'handbuch/handbuch.css'];
$checked = 0;
$rules = 0;

foreach ($sheets as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    $assert(
        is_string($source) && $source !== '',
        'Stylesheet is missing or empty: ' . $relative
    );
    $source = (string) $source;
    $checked++;

    // Strip comments and quoted strings so braces inside them cannot lie.
    $clean = preg_replace('~/\*.*?\*/~s', '', $source) ?? '';
    $clean = preg_replace('~"(?:[^"\\\\]|\\\\.)*"~', '""', $clean) ?? '';
    $clean = preg_replace("~'(?:[^'\\\\]|\\\\.)*'~", "''", $clean) ?? '';

    $depth = 0;
    $line = 1;
    $underflow = null;
    $length = strlen($clean);
    for ($index = 0; $index < $length; $index++) {
        $character = $clean[$index];
        if ($character === "\n") {
            $line++;
        } elseif ($character === '{') {
            $depth++;
        } elseif ($character === '}') {
            $depth--;
            if ($depth < 0 && $underflow === null) {
                $underflow = $line;
            }
        }
    }
    $assert(
        $underflow === null,
        $relative . ' closes a block that was never opened, first at line '
            . $underflow
    );
    $assert(
        $depth === 0,
        $relative . ' leaves ' . $depth . ' block(s) open at the end'
    );

    // A declaration directly behind a closing brace has lost its selector.
    preg_match_all(
        '~\}\s*\n[ \t]+[-a-zA-Z]+\s*:~',
        $clean,
        $orphans,
        PREG_OFFSET_CAPTURE
    );
    $orphanLines = [];
    foreach ($orphans[0] as $orphan) {
        $orphanLines[] = substr_count(
            substr($clean, 0, (int) $orphan[1]),
            "\n"
        ) + 1;
    }
    $assert(
        $orphanLines === [],
        $relative . ' carries declarations without a selector, after line(s) '
            . implode(', ', $orphanLines)
    );

    // Every block needs something in front of it.
    preg_match_all('~(?m)^\s*\{~', $clean, $headless, PREG_OFFSET_CAPTURE);
    $assert(
        $headless[0] === [],
        $relative . ' opens a block without a selector'
    );

    $rules += (int) preg_match_all('~\{~', $clean);
}

$assert($checked === count($sheets), 'Not every stylesheet was parsed');
$assert(
    $rules > 500,
    'The stylesheets shrank unexpectedly to ' . $rules . ' blocks'
);

printf(
    "stylesheet integrity: OK (%d assertions, %d sheets, %d blocks)\n",
    $assertions,
    $checked,
    $rules
);
