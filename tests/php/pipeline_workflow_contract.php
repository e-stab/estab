<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $path): string {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $contents;
};

$ci = $read($root . '/.github/workflows/ci.yml');
$audit = $read($root . '/.github/workflows/audit.yml');
$osv = $read($root . '/.github/workflows/osv-scanner.yml');
$dependencyReview = $read($root . '/.github/workflows/dependency-review.yml');
$dependabot = $read($root . '/.github/dependabot.yml');
$technicalGuide = $read($root . '/docs/TECHNIK.md');
// Verified against the checks the suite actually registers, not its source text.
$staticChecks = (static function (string $root): array {
    $output = [];
    $status = 0;
    exec(
        'sh ' . escapeshellarg($root . '/tests/static/run.sh') . ' --list 2>&1',
        $output,
        $status
    );
    if ($status !== 0) {
        throw new RuntimeException(
            'Could not list the static suite checks: ' . implode("\n", $output)
        );
    }
    return array_values(array_filter(array_map('trim', $output), 'strlen'));
})($root);
$composerManifest = json_decode(
    $read($root . '/composer.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$composerLock = json_decode(
    $read($root . '/composer.lock'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$packageManifest = json_decode(
    $read($root . '/package.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$packageLock = json_decode(
    $read($root . '/package-lock.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$pythonAuditLock = $read($root . '/requirements-audit.txt');

$ciActionRefs = [];
preg_match_all(
    '/^\s*uses:\s+\S+@([^\s#]+)/m',
    $ci,
    $ciActionRefs
);

$assert(
    !str_contains($ci, 'id: provenance')
    && !str_contains($ci, 'migration/verify_provenance.py')
    && !str_contains($ci, 'PROVENANCE_OUTCOME')
    && !str_contains($ci, 'fetch-tags: true')
    && !in_array('provenance_security', $staticChecks, true)
    && str_contains($ci, 'name: Enforce static results')
    && str_contains($ci, 'if: ${{ !cancelled() }}')
    && substr_count($ci, 'continue-on-error: true') >= 4,
    'CI still enforces retired SVN provenance or does not aggregate current checks'
);
$assert(
    count($ciActionRefs[1] ?? []) > 0
        && count(array_filter(
            $ciActionRefs[1],
            static fn (string $ref): bool =>
                preg_match('~\A[0-9a-f]{40}\z~D', $ref) !== 1
        )) === 0,
    'CI actions are not pinned to complete 40-character commit SHAs'
);
$assert(
    str_contains($audit, 'git grep -nI -E')
    && !str_contains($audit, 'grep -RInE')
    && str_contains($audit, '| tee audit-results/suspicious-constructs.txt'),
    'Repository hygiene can scan its own growing report'
);
$assert(
    str_contains($audit, 'pipx install semgrep==1.172.0')
    && !str_contains($audit, '--config p/bash')
    && str_contains($audit, '--config .semgrep/suspicious.yml'),
    'Semgrep is unpinned or still loads the removed Bash registry pack'
);
$assert(
    str_contains($audit, "-printf '%P\\0'")
    && str_contains($audit, "git grep -Ilz '^#!")
    && str_contains($audit, 'sort -zu'),
    'ShellCheck inputs are not normalized before deduplication'
);
$assert(
    str_contains($audit, 'name: Enforce PHP audit results')
    && str_contains($audit, 'name: Validate PHP audit inputs')
    && str_contains($audit, 'PHP_INPUTS_OUTCOME: ${{ steps.php_inputs.outcome }}')
    && str_contains($audit, 'PHPSTAN_OUTCOME: ${{ steps.phpstan.outcome }}')
    && str_contains($audit, 'COMPOSER_AUDIT_OUTCOME: ${{ steps.composer_audit.outcome }}')
    && str_contains(
        $audit,
        'vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
    )
    && str_contains($audit, 'vendor/bin/phpcs')
    && str_contains($audit, 'name: Enforce Python audit results')
    && str_contains($audit, 'name: Validate Python audit inputs')
    && str_contains($audit, 'PYTHON_INPUTS_OUTCOME: ${{ steps.python_inputs.outcome }}')
    && str_contains($audit, 'RUFF_OUTCOME: ${{ steps.ruff.outcome }}')
    && str_contains($audit, 'BANDIT_OUTCOME: ${{ steps.bandit.outcome }}')
    && str_contains($audit, '--require-hashes')
    && str_contains($audit, '--requirement requirements-audit.txt')
    && !str_contains($audit, 'accept_success_or_skip')
    && !str_contains($audit, 'success|skipped'),
    'PHP or Python audits can skip required source, manifest, or scan outcomes'
);
$assert(
    str_contains($audit, 'name: Validate JavaScript audit inputs')
    && str_contains(
        $audit,
        'JAVASCRIPT_INPUTS_OUTCOME: ${{ steps.javascript_inputs.outcome }}'
    )
    && str_contains($audit, 'name: Check JavaScript syntax')
    && str_contains($audit, 'node --check')
    && str_contains($audit, 'npm ci --ignore-scripts')
    && str_contains($audit, 'npm audit --audit-level=high')
    && str_contains($audit, 'run: npm run lint')
    && str_contains($audit, 'name: Enforce JavaScript audit results'),
    'JavaScript source, installation, audit, or lint can remain unaudited'
);
$assert(
    ($composerManifest['license'] ?? null) === 'GPL-3.0-only'
    && ($packageManifest['license'] ?? null) === 'GPL-3.0-only'
    && ($packageLock['packages']['']['license'] ?? null) === 'GPL-3.0-only',
    'Dependency manifests do not declare the project GPL-3.0-only license'
);
$assert(
    is_array($composerLock)
    && count(array_merge(
        $composerLock['packages'] ?? [],
        $composerLock['packages-dev'] ?? []
    )) > 0
    && is_array($packageLock)
    && count($packageLock['packages'] ?? []) > 0
    && preg_match(
        '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*==[0-9]/m',
        $pythonAuditLock
    ) === 1,
    'A dependency lock graph is missing or empty'
);
$assert(
    str_contains($osv, 'name: Validate dependency scan inputs')
    && str_contains($osv, 'needs: dependency-inputs')
    && str_contains($osv, 'composer.json composer.lock')
    && str_contains($osv, 'requirements-audit.txt')
    && str_contains($osv, 'package.json package-lock.json')
    && str_contains($osv, 'fail-on-vuln: true')
    && str_contains($osv, 'upload-sarif: true'),
    'OSV can run without validated nonempty locked dependency graphs'
);
$assert(
    str_contains($dependabot, 'package-ecosystem: composer')
    && str_contains($dependabot, 'package-ecosystem: pip')
    && str_contains($dependabot, 'package-ecosystem: npm')
    && str_contains($dependabot, 'package-ecosystem: github-actions'),
    'Dependabot does not cover every declared dependency ecosystem'
);
$assert(
    str_contains($technicalGuide, 'Die Dependency-Prüfungen arbeiten fail-closed')
    && str_contains($technicalGuide, '`requirements-audit.txt`')
    && str_contains($technicalGuide, 'composer audit --locked --no-interaction')
    && str_contains($technicalGuide, 'pip-audit --requirement requirements-audit.txt')
    && str_contains($technicalGuide, 'npm audit --audit-level=high')
    && str_contains($technicalGuide, 'osv-scanner scan source --recursive .')
    && !str_contains($technicalGuide, 'Diese fehlen derzeit noch'),
    'Technical guide does not document the fail-closed dependency audit contract'
);
$assert(
    str_contains($dependencyReview, 'pull-requests: write')
    && str_contains($dependencyReview, 'comment-summary-in-pr: always'),
    'Dependency Review cannot publish its configured pull-request summary'
);

printf("Pipeline workflow contract: OK (%d assertions)\n", $assertions);
