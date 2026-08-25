<?php

declare(strict_types=1);

/**
 * Catalogue of the operating requirements this application must uphold.
 *
 * These requirements do not come from a service regulation. They come from the
 * operator, and they exist because the application is used by people who know
 * the paper form and not the software. The yardstick is uncomfortable but
 * unambiguous: whoever falls back to paper during an operation because the
 * application got in the way has uncovered a defect of the application, not a
 * defect of their operating skill.
 *
 * The mechanism mirrors app/dv_rules.php exactly -- origin, reference,
 * requirement, loud failure on an unknown identifier, enforced test coverage.
 * The catalogues stay apart because they differ in authority, not in rigour:
 * a service regulation binds from outside and cannot be argued with, while an
 * operating requirement may be revised when it turns out to serve nobody.
 * Merging them would make it impossible to answer what the service regulation
 * demands, which is the question an audit asks.
 */

const ESTAB_UX_ORIGIN_BETREIBER =
    'Bedienanforderungen des Betreibers, SPEC.md Abschnitt 5.10';

/**
 * @return array<string, array{origin:string,reference:string,requirement:string}>
 */
function estab_ux_rules(): array
{
    return [];
}

/**
 * Resolve one rule, failing loudly on an identifier that does not exist.
 *
 * A service-regulation identifier does not resolve here. The two catalogues
 * must not quietly merge through a test that reaches into the wrong one.
 *
 * @return array{origin:string,reference:string,requirement:string}
 */
function estab_ux_rule(string $id): array
{
    $rules = estab_ux_rules();
    if (!array_key_exists($id, $rules)) {
        throw new InvalidArgumentException(
            'Unknown operating rule: ' . $id
        );
    }
    return $rules[$id];
}

/**
 * Message for a test that covers one rule.
 *
 * Calling this records the identifier when ESTAB_UX_COVERAGE names a file, so
 * the registry test can prove that every catalogued rule has a test.
 */
function estab_ux_requirement(string $id, string $detail = ''): string
{
    $rule = estab_ux_rule($id);
    $coverage = getenv('ESTAB_UX_COVERAGE');
    if (is_string($coverage) && $coverage !== '') {
        file_put_contents($coverage, $id . "\n", FILE_APPEND | LOCK_EX);
    }
    return '[' . $id . '] ' . $rule['origin'] . ', ' . $rule['reference']
        . ': ' . $rule['requirement']
        . ($detail === '' ? '' : ' — ' . $detail);
}
