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
$dependabot = $read($root . '/.github/dependabot.yml');
$dependencyReview = $read($root . '/.github/workflows/dependency-review.yml');
$staticSuite = $read($root . '/tests/static/run.sh');

$assert(
    !str_contains($ci, 'id: provenance')
    && !str_contains($ci, 'migration/verify_provenance.py')
    && !str_contains($ci, 'PROVENANCE_OUTCOME')
    && !str_contains($ci, 'fetch-tags: true')
    && !str_contains($staticSuite, 'tests/php/provenance_security.php')
    && str_contains($ci, 'name: Enforce static results')
    && str_contains($ci, 'if: ${{ !cancelled() }}')
    && substr_count($ci, 'continue-on-error: true') >= 4,
    'CI still enforces retired SVN provenance or does not aggregate current checks'
);
$assert(
    str_contains($ci, 'name: Verify source tree hygiene')
    && str_contains($ci, 'id: source_tree_hygiene')
    && str_contains($ci, 'run: sh tests/static/source_tree_hygiene.sh')
    && str_contains(
        $ci,
        "if: \${{ always() && steps.source_tree_hygiene.outcome == 'success' }}"
    )
    && str_contains($ci, '--env ESTAB_SOURCE_TREE_HYGIENE_VERIFIED=1')
    && str_contains(
        $ci,
        'SOURCE_TREE_HYGIENE_OUTCOME: ${{ steps.source_tree_hygiene.outcome }}'
    )
    && str_contains(
        $ci,
        'require_success source-tree-hygiene "$SOURCE_TREE_HYGIENE_OUTCOME"'
    )
    && str_contains($staticSuite, 'command -v git >/dev/null 2>&1')
    && str_contains(
        $staticSuite,
        '${ESTAB_SOURCE_TREE_HYGIENE_VERIFIED:-}'
    ),
    'Git-free PHP execution can bypass or omit the source-tree hygiene gate'
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
    && str_contains($audit, 'PHP_SYNTAX_OUTCOME: ${{ steps.php_syntax.outcome }}')
    && str_contains($audit, 'PHPSTAN_OUTCOME: ${{ steps.phpstan.outcome }}')
    && str_contains($audit, 'PHPCS_OUTCOME: ${{ steps.phpcs.outcome }}')
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
    stripos($audit, 'composer') === false
    && !str_contains($audit, 'pip-audit')
    && !str_contains($audit, 'python_dependencies')
    && !str_contains($audit, 'package_present')
    && !str_contains($audit, 'npm_install')
    && !str_contains($audit, 'npm_audit')
    && !str_contains($audit, 'npm_lint'),
    'Audit workflow contains package-manager branches without manifests'
);
$assert(
    !str_contains($audit, 'workflow-lint:')
    && !str_contains($audit, 'actionlint')
    && str_contains(
        $ci,
        'docker.io/rhysd/actionlint@sha256:'
    ),
    'Actionlint is duplicated outside the pinned CI gate'
);
$assert(
    substr_count($dependabot, 'package-ecosystem:') === 1
    && str_contains($dependabot, 'package-ecosystem: github-actions')
    && stripos($dependabot, 'composer') === false
    && !str_contains($dependabot, 'package-ecosystem: pip')
    && !str_contains($dependabot, 'package-ecosystem: npm')
    && !is_file($root . '/.github/workflows/osv-scanner.yml'),
    'Manifest-less dependency update or OSV workflows were reintroduced'
);
$assert(
    str_contains($dependencyReview, 'pull-requests: write')
    && str_contains($dependencyReview, 'comment-summary-in-pr: always'),
    'Dependency Review cannot publish its configured pull-request summary'
);

printf("Pipeline workflow contract: OK (%d assertions)\n", $assertions);
