<?php

declare(strict_types=1);

/**
 * Prove that every catalogued service-regulation rule has a test behind it.
 *
 * The catalogue is only worth something if it cannot drift: a rule that nobody
 * checks is a promise without cover, and a rule identifier that no longer
 * exists silently disables the test that used it. Coverage is collected by
 * running the tests that reference rules and recording the identifiers they
 * actually resolve at runtime.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$rules = estab_dv_rules();
$assert($rules !== [], 'The service-regulation catalogue is empty');

foreach ($rules as $id => $rule) {
    $assert(
        preg_match('~\A[A-Z][A-Z0-9]*(?:-[A-Z0-9]+)+\z~D', $id) === 1,
        'Rule identifier is not upper-case and hyphenated: ' . $id
    );
    foreach (['source', 'reference', 'requirement'] as $field) {
        $assert(
            isset($rule[$field]) && is_string($rule[$field])
                && trim($rule[$field]) !== '',
            'Rule ' . $id . ' has no ' . $field
        );
    }
    $assert(
        str_ends_with(rtrim($rule['requirement']), '.'),
        'Rule ' . $id . ' does not state its requirement as a sentence'
    );
    $assert(
        in_array($rule['source'], estab_dv_sources(), true),
        'Rule ' . $id . ' cites a document outside the catalogue'
    );
}

// The catalogue names its own sources, so adding one is a single edit. A
// hand-kept second list here used to reject every rule whose source someone
// had declared but forgotten to repeat, and the message blamed the rule.
$sources = estab_dv_sources();
$assert($sources !== [], 'The catalogue names no source document');
foreach ($sources as $key => $label) {
    $assert(
        is_string($key) && $key !== '',
        'A source document has no short name'
    );
    $assert(
        is_string($label) && trim($label) !== '',
        'Source ' . (string) $key . ' has no designation'
    );
}
$assert(
    count(array_unique($sources)) === count($sources),
    'Two source documents carry the same designation'
);
$assert(
    in_array(ESTAB_DV_SOURCE_MELDEWESEN, $sources, true),
    'The catalogue does not know the training document '
        . '"Grundlagen des Meldewesens"'
);

// A rule that departs from an older source must name what it displaces, or
// the reason for the departure is lost the moment the author leaves. The
// note is optional, but a malformed one is worse than none: it looks like
// provenance and carries none.
foreach ($rules as $id => $rule) {
    estab_dv_rule_displacement($rule);
    $assertions++;
}

$rejects = static function (mixed $note) use ($assert): void {
    $rejected = false;
    try {
        estab_dv_rule_displacement(['verdraengt' => $note]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $assert(
        $rejected,
        'A malformed displacement note is accepted: '
            . var_export($note, true)
    );
};

$rejects('Dienstvorschrift 1-101, Kapitel 4.3.1.11');
$rejects([]);
$rejects(['source' => ESTAB_DV_SOURCE_DV_1_101]);
$rejects(['reference' => 'Kapitel 4.3.1.11']);
$rejects(['source' => ESTAB_DV_SOURCE_DV_1_101, 'reference' => '   ']);
$rejects(['source' => 'Erfundenes Dokument', 'reference' => 'Kapitel 1']);

$assert(
    estab_dv_rule_displacement(['requirement' => 'x']) === null,
    'A rule without a displacement note is treated as displacing something'
);
$assert(
    estab_dv_rule_displacement([
        'verdraengt' => [
            'source' => ESTAB_DV_SOURCE_DV_1_101,
            'reference' => 'Kapitel 4.3.1.11',
        ],
    ]) === [
        'source' => ESTAB_DV_SOURCE_DV_1_101,
        'reference' => 'Kapitel 4.3.1.11',
    ],
    'A well-formed displacement note is not returned unchanged'
);

$assert(
    estab_dv_rule(array_key_first($rules)) !== [],
    'A catalogued rule cannot be resolved'
);

$unknownRejected = false;
try {
    estab_dv_rule('NO-SUCH-RULE');
} catch (InvalidArgumentException) {
    $unknownRejected = true;
}
$assert($unknownRejected, 'An unknown rule identifier is accepted');

// Narrow the candidate set by source scan, then prove coverage by execution.
$candidates = [];
foreach (glob($root . '/tests/php/*.php') ?: [] as $path) {
    if (basename($path) === basename(__FILE__)) {
        continue;
    }
    $source = file_get_contents($path);
    if (is_string($source) && str_contains($source, 'estab_dv_requirement(')) {
        $candidates[] = $path;
    }
}
sort($candidates, SORT_STRING);
$assert(
    $candidates !== [],
    'No test states which service-regulation rule it covers'
);

$coverageFile = tempnam(sys_get_temp_dir(), 'estab-dv-coverage-');
if (!is_string($coverageFile)) {
    throw new RuntimeException('Could not create the coverage file');
}
try {
    $binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    foreach ($candidates as $path) {
        $output = [];
        $status = 0;
        exec(
            'ESTAB_DV_COVERAGE=' . escapeshellarg($coverageFile) . ' '
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

$uncovered = array_values(array_diff(array_keys($rules), $covered));
sort($uncovered, SORT_STRING);
$assert(
    $uncovered === [],
    'Catalogued rules without a test: ' . implode(', ', $uncovered)
);

$stray = array_values(array_diff($covered, array_keys($rules)));
$assert(
    $stray === [],
    'Tests cover rule identifiers that are not catalogued: '
        . implode(', ', $stray)
);

printf(
    "DV rule registry: OK (%d assertions, %d rules covered by %d tests)\n",
    $assertions,
    count($rules),
    count($candidates)
);
