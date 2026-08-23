<?php

declare(strict_types=1);

/**
 * The token rewriter must tell a subscript from an array literal.
 *
 * tools/modernize_php_tokens.php quotes legacy bareword array keys so that
 * $row[key] becomes $row['key']. It recognised the pattern by looking only at
 * the brackets around the word, so an array literal holding a constant --
 * [ESTAB_MESSAGE_STATUS_DRAFT] -- looked exactly the same. Rewriting that
 * turns the constant into a string and changes what the code means, silently.
 */

$root = dirname(__DIR__, 2);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$probe = <<<'PROBE'
<?php
const ESTAB_PROBE_ONE = 1;
$row = ['key' => 1];
$subscript = $row[key];
$afterCall = probe_call()[bareword];
$afterIndex = $row['key'][nested];
$literal = [ESTAB_PROBE_ONE];
$nestedLiteral = ['a' => [ESTAB_PROBE_ONE]];
$argument = probe_call([ESTAB_PROBE_ONE]);
$returned = static fn (): array => [ESTAB_PROBE_ONE];
$quoted = $row['key'];
$constantSubscript = $GLOBALS[ESTAB_PROBE_ONE];
PROBE;

$workspace = sys_get_temp_dir() . '/estab-modernize-' . bin2hex(random_bytes(8));
if (!mkdir($workspace . '/tools', 0700, true) || !mkdir($workspace . '/4fach', 0700, true)) {
    throw new RuntimeException('Could not create the rewriter workspace');
}
try {
    $tool = file_get_contents($root . '/tools/modernize_php_tokens.php');
    $assert(is_string($tool), 'Could not read the token rewriter');
    file_put_contents($workspace . '/tools/modernize_php_tokens.php', (string) $tool);
    file_put_contents($workspace . '/4fach/probe.php', $probe);

    $binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $output = [];
    $status = 0;
    exec(
        escapeshellarg($binary) . ' '
            . escapeshellarg($workspace . '/tools/modernize_php_tokens.php')
            . ' 2>&1',
        $output,
        $status
    );
    $report = implode("\n", $output);
    $assert(
        preg_match('~would rewrite: (\d+) keys~', $report, $match) === 1,
        'The rewriter did not report a count: ' . $report
    );
    $found = (int) $match[1];

    // Exactly the three genuine subscripts, and nothing else.
    $assert(
        $found === 3,
        'The rewriter wants to change ' . $found . ' places instead of the'
            . ' three real subscripts. An array literal holding a constant is'
            . ' not a bareword key.'
    );

    // Now let it write, and read the result back.
    $output = [];
    exec(
        escapeshellarg($binary) . ' '
            . escapeshellarg($workspace . '/tools/modernize_php_tokens.php')
            . ' --write 2>&1',
        $output,
        $status
    );
    $rewritten = file_get_contents($workspace . '/4fach/probe.php');
    $assert(is_string($rewritten), 'The rewritten probe cannot be read');
    $rewritten = (string) $rewritten;

    // Eine eigene Konstante als Index ist gemeint. Wuerde sie quotiert,
    // stuende dort still ihr Name statt ihres Wertes, und der Zugriff ginge
    // ins Leere, ohne dass irgendetwas auffaellt.
    $assert(
        str_contains($rewritten, '$GLOBALS[ESTAB_PROBE_ONE]'),
        'Der Modernisierer quotiert eine eigene Konstante als Index.'
    );

    foreach ([
        "\$row['key']" => 'the plain subscript',
        "probe_call()['bareword']" => 'the subscript behind a call',
        "\$row['key']['nested']" => 'the nested subscript',
    ] as $needle => $what) {
        $assert(
            str_contains($rewritten, $needle),
            'The rewriter did not quote ' . $what
        );
    }
    foreach ([
        '[ESTAB_PROBE_ONE]' => 'the array literal',
        "['a' => [ESTAB_PROBE_ONE]]" => 'the nested array literal',
        'probe_call([ESTAB_PROBE_ONE])' => 'the literal passed as an argument',
        '=> [ESTAB_PROBE_ONE]' => 'the returned literal',
    ] as $needle => $what) {
        $assert(
            str_contains($rewritten, $needle),
            'The rewriter turned the constant in ' . $what . ' into a string'
        );
    }
    $assert(
        !str_contains($rewritten, "['ESTAB_PROBE_ONE']"),
        'A constant was quoted into a string somewhere in the probe'
    );
    $assert(
        $status === 0,
        'The rewriter reported a failure while writing'
    );
} finally {
    foreach (['4fach/probe.php', 'tools/modernize_php_tokens.php'] as $file) {
        @unlink($workspace . '/' . $file);
    }
    @rmdir($workspace . '/4fach');
    @rmdir($workspace . '/tools');
    @rmdir($workspace);
}

printf("modernize tokens: OK (%d assertions)\n", $assertions);
