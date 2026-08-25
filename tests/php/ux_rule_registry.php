<?php

declare(strict_types=1);

/**
 * Prove that every catalogued operating requirement has a test behind it.
 *
 * The operating catalogue is kept apart from the service-regulation one on
 * purpose. Both are enforced with the same rigour, but they differ in who may
 * change them: a service regulation binds the application from outside and is
 * not negotiable, while an operating requirement is the operator's own
 * decision and may be revised when it turns out to serve nobody. Mixing them
 * would destroy the answer to the question an audit asks -- what does the
 * service regulation demand, and where does it say so.
 *
 * The catalogue may be empty. It fills as the operating requirements of the
 * specification are implemented, and an empty catalogue is not a defect but a
 * starting point.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$rules = estab_ux_rules();

foreach ($rules as $id => $rule) {
    $assert(
        preg_match('~\AUX(?:-[A-Z0-9ÄÖÜ]+)+\z~u', $id) === 1,
        'Operating rule identifier does not start with UX- : ' . $id
    );
    foreach (['origin', 'reference', 'requirement'] as $field) {
        $assert(
            isset($rule[$field]) && is_string($rule[$field])
                && trim($rule[$field]) !== '',
            'Operating rule ' . $id . ' has no ' . $field
        );
    }
    $assert(
        str_ends_with(rtrim($rule['requirement']), '.'),
        'Operating rule ' . $id . ' does not state its requirement as a sentence'
    );
    $assert(
        $rule['origin'] === ESTAB_UX_ORIGIN_BETREIBER,
        'Operating rule ' . $id . ' cites an origin outside the catalogue'
    );
}

// A rule identifier that no longer exists silently disables the test that used
// it, so resolution must fail loudly rather than return an empty rule.
$unknownRejected = false;
try {
    estab_ux_rule('UX-NO-SUCH-RULE');
} catch (InvalidArgumentException) {
    $unknownRejected = true;
}
$assert($unknownRejected, 'An unknown operating rule identifier is accepted');

// A service-regulation identifier must not resolve here either, or the two
// catalogues would quietly merge.
$foreignRejected = false;
try {
    estab_ux_rule('NV-FELDNUMMERN');
} catch (InvalidArgumentException) {
    $foreignRejected = true;
}
$assert(
    $foreignRejected,
    'A service-regulation identifier resolves in the operating catalogue'
);

// Narrow the candidate set by source scan, then prove coverage by execution.
$candidates = [];
foreach (glob($root . '/tests/php/*.php') ?: [] as $path) {
    if (basename($path) === basename(__FILE__)) {
        continue;
    }
    $source = file_get_contents($path);
    if (is_string($source) && str_contains($source, 'estab_ux_requirement(')) {
        $candidates[] = $path;
    }
}
sort($candidates, SORT_STRING);
$assert(
    $rules === [] || $candidates !== [],
    'No test states which operating rule it covers'
);

$covered = [];
if ($candidates !== []) {
    $coverageFile = tempnam(sys_get_temp_dir(), 'estab-ux-coverage-');
    if (!is_string($coverageFile)) {
        throw new RuntimeException('Could not create the coverage file');
    }
    try {
        $binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        foreach ($candidates as $path) {
            $output = [];
            $status = 0;
            exec(
                'ESTAB_UX_COVERAGE=' . escapeshellarg($coverageFile) . ' '
                    . escapeshellarg($binary) . ' ' . escapeshellarg($path)
                    . ' 2>&1',
                $output,
                $status
            );
            $assert(
                $status === 0,
                'Rule-covering test failed while collecting coverage: '
                    . basename($path) . "\n" . implode("\n", $output)
            );
        }
        $recorded = file_get_contents($coverageFile);
        $covered = array_values(array_unique(array_filter(
            array_map('trim', explode("\n", is_string($recorded) ? $recorded : '')),
            'strlen'
        )));
    } finally {
        @unlink($coverageFile);
    }
}

$uncovered = array_values(array_diff(array_keys($rules), $covered));
sort($uncovered, SORT_STRING);
$assert(
    $uncovered === [],
    'Operating rules without a test: ' . implode(', ', $uncovered)
);

printf(
    "UX rule registry: OK (%d assertions, %d rules covered by %d tests)\n",
    $assertions,
    count($rules),
    count($candidates)
);
