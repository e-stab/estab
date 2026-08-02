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
$dependencyReview = $read($root . '/.github/workflows/dependency-review.yml');

$assert(
    str_contains($ci, 'id: provenance')
    && str_contains($ci, 'PROVENANCE_OUTCOME: ${{ steps.provenance.outcome }}')
    && str_contains($ci, 'name: Enforce static and provenance results')
    && str_contains($ci, 'if: ${{ !cancelled() }}')
    && substr_count($ci, 'continue-on-error: true') >= 5,
    'CI does not preserve strict gates while allowing independent checks to finish'
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
    && str_contains($audit, 'PHPSTAN_OUTCOME: ${{ steps.phpstan.outcome }}')
    && str_contains($audit, 'COMPOSER_AUDIT_OUTCOME: ${{ steps.composer_audit.outcome }}')
    && str_contains($audit, 'name: Enforce Python audit results')
    && str_contains($audit, 'RUFF_OUTCOME: ${{ steps.ruff.outcome }}')
    && str_contains($audit, 'BANDIT_OUTCOME: ${{ steps.bandit.outcome }}'),
    'Audit jobs do not aggregate quality and security check outcomes'
);
$assert(
    str_contains($audit, 'source_present=true')
    && str_contains($audit, 'name: Check JavaScript syntax')
    && str_contains($audit, 'node --check')
    && str_contains($audit, 'name: Enforce JavaScript audit results'),
    'JavaScript sources without package.json remain unaudited'
);
$assert(
    str_contains($dependencyReview, 'pull-requests: write')
    && str_contains($dependencyReview, 'comment-summary-in-pr: always'),
    'Dependency Review cannot publish its configured pull-request summary'
);

printf("Pipeline workflow contract: OK (%d assertions)\n", $assertions);
