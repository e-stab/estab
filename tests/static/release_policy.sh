#!/bin/sh

set -eu

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd -P)
policy_verifier=$repo_root/tools/verify-github-release-policy.sh
fixture=$(mktemp -d "${TMPDIR:-/tmp}/estab-release-policy.XXXXXX")
trap 'rm -rf -- "$fixture"' EXIT HUP INT TERM

grep -Fq 'command -v jq >/dev/null 2>&1' "$policy_verifier"
grep -Fq 'die "jq is required"' "$policy_verifier"

fake_bin=$fixture/bin
mkdir "$fake_bin"
cat >"$fake_bin/gh" <<'EOF'
#!/bin/sh
set -eu

fake_gh_fail()
{
    printf 'unexpected fake gh invocation: %s\n' "$*" >&2
    exit 2
}

if [ "$#" -ne 8 ] ||
    [ "$1" != api ] ||
    [ "$2" != --method ] ||
    [ "$3" != GET ] ||
    [ "$4" != --header ] ||
    [ "$6" != --header ] ||
    [ "$7" != "X-GitHub-Api-Version: 2026-03-10" ]; then
    fake_gh_fail "$@"
fi

api_path=$8
case "$api_path" in
    repos/e-stab/estab/immutable-releases)
        [ "$5" = "Accept: application/vnd.github+json" ] ||
            fake_gh_fail "$@"
        printf '%s\n' "${FAKE_IMMUTABLE_STATE:?}"
        ;;
    repos/e-stab/estab/rulesets/42\?includes_parents=true)
        [ "$5" = "Accept: application/vnd.github+json" ] ||
            fake_gh_fail "$@"
        printf '%s\n' "${FAKE_RULESET_STATE:?}"
        ;;
    repos/e-stab/estab/commits/tags/release-1.2.3)
        [ "$5" = "Accept: application/vnd.github.sha" ] ||
            fake_gh_fail "$@"
        printf '%s\n' "${FAKE_REMOTE_COMMIT:?}"
        ;;
    *)
        fake_gh_fail "$@"
        ;;
esac
EOF
chmod 0755 "$fake_bin/gh"

# The pinned PHP CLI image intentionally contains neither gh nor jq. gh is
# always isolated above. Keep a real jq on developer/runner hosts, but provide
# an exact PHP test double when jq is absent so this security contract remains
# hermetic without weakening the production verifier's hard jq dependency.
using_jq_test_double=0
if ! command -v jq >/dev/null 2>&1; then
    using_jq_test_double=1
    command -v php >/dev/null 2>&1 || {
        echo "Release policy test: php is required for the jq test double" >&2
        exit 1
    }
    cat >"$fake_bin/jq" <<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

function jq_test_fail(string $message, int $status = 64): never
{
    fwrite(STDERR, "release-policy jq test double: {$message}\n");
    exit($status);
}

function jq_test_normalize(string $filter): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($filter));
    if (!is_string($normalized) || $normalized === '') {
        jq_test_fail('filter is empty');
    }
    return $normalized;
}

/** @return array<mixed> */
function jq_test_read_json(?string $inputFile): array
{
    $bytes = $inputFile === null
        ? stream_get_contents(STDIN)
        : file_get_contents($inputFile);
    if (!is_string($bytes) || $bytes === '') {
        jq_test_fail('input JSON is missing', 2);
    }
    try {
        $value = json_decode(
            $bytes,
            true,
            512,
            JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
        );
    } catch (JsonException $exception) {
        jq_test_fail('input JSON is invalid: ' . $exception->getMessage(), 4);
    }
    if (!is_array($value)) {
        jq_test_fail('input JSON must be an object or array', 4);
    }
    return $value;
}

function jq_test_emit(mixed $value, bool $raw, bool $exitStatus): never
{
    if ($raw) {
        if (is_string($value) || is_int($value) || is_float($value)) {
            echo (string) $value, "\n";
        } elseif (is_bool($value)) {
            echo $value ? "true\n" : "false\n";
        } elseif ($value === null) {
            echo "null\n";
        } else {
            jq_test_fail('raw output received a non-scalar value');
        }
    } else {
        try {
            echo json_encode(
                $value,
                JSON_THROW_ON_ERROR
                    | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
            ), "\n";
        } catch (JsonException $exception) {
            jq_test_fail(
                'could not encode output JSON: ' . $exception->getMessage(),
                5
            );
        }
    }

    if ($exitStatus && ($value === false || $value === null)) {
        exit(1);
    }
    exit(0);
}

/**
 * @param array<string,string> $actual
 * @param list<string> $expected
 */
function jq_test_require_arguments(array $actual, array $expected): void
{
    $actualNames = array_keys($actual);
    sort($actualNames, SORT_STRING);
    sort($expected, SORT_STRING);
    if ($actualNames !== $expected) {
        jq_test_fail('filter received an unexpected --arg set');
    }
}

$exitStatus = false;
$raw = false;
$nullInput = false;
$namedArguments = [];
$positionals = [];
$arguments = array_slice($argv, 1);
for ($index = 0, $count = count($arguments); $index < $count; $index++) {
    $argument = $arguments[$index];
    switch ($argument) {
        case '-e':
            if ($exitStatus) {
                jq_test_fail('duplicate -e');
            }
            $exitStatus = true;
            break;
        case '-r':
            if ($raw) {
                jq_test_fail('duplicate -r');
            }
            $raw = true;
            break;
        case '-n':
            if ($nullInput) {
                jq_test_fail('duplicate -n');
            }
            $nullInput = true;
            break;
        case '--arg':
            if ($index + 2 >= $count) {
                jq_test_fail('--arg requires a name and value');
            }
            $name = $arguments[++$index];
            $value = $arguments[++$index];
            if (
                preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
                || array_key_exists($name, $namedArguments)
            ) {
                jq_test_fail('invalid or duplicate --arg name');
            }
            $namedArguments[$name] = $value;
            break;
        default:
            if (str_starts_with($argument, '-')) {
                jq_test_fail('unsupported option ' . $argument);
            }
            $positionals[] = $argument;
            break;
    }
}

if ($positionals === [] || count($positionals) > 2) {
    jq_test_fail('expected one filter and at most one input file');
}
$filter = jq_test_normalize($positionals[0]);
$inputFile = $positionals[1] ?? null;
if ($nullInput && $inputFile !== null) {
    jq_test_fail('-n must not receive an input file');
}

$rulesetPredicate = jq_test_normalize(<<<'JQ'
.target == "tag"
and .enforcement == "active"
and has("bypass_actors")
and .bypass_actors == []
and .conditions.ref_name.include == ["~ALL"]
and .conditions.ref_name.exclude == []
and ([.rules[].type] | index("update")) != null
and ([.rules[].type] | index("deletion")) != null
and ([.rules[].type] | index("creation")) == null
JQ);
$evidenceConstructor = jq_test_normalize(<<<'JQ'
{
  format: "estab-github-release-policy-v1",
  repository: $repository,
  release_tag: $release_tag,
  git_commit: $git_commit,
  immutable_releases: true,
  tag_ruleset: {
    id: $ruleset_id,
    name: $ruleset_name,
    source: $ruleset_source,
    source_type: $ruleset_source_type,
    updated_at: $ruleset_updated_at,
    target: "tag",
    enforcement: "active",
    include: ["~ALL"],
    exclude: [],
    bypass_actors: [],
    rules: ["update", "deletion"]
  }
}
JQ);
$evidencePredicate = jq_test_normalize(<<<'JQ'
.format == "estab-github-release-policy-v1"
and .repository == "e-stab/estab"
and .release_tag == "release-1.2.3"
and .git_commit == $commit
and .immutable_releases == true
and .tag_ruleset.include == ["~ALL"]
and .tag_ruleset.bypass_actors == []
and .tag_ruleset.rules == ["update", "deletion"]
JQ);

if ($filter === '.enabled == true') {
    if (!$exitStatus || $raw || $nullInput || $inputFile !== null) {
        jq_test_fail('immutable-policy predicate used unexpected arguments');
    }
    jq_test_require_arguments($namedArguments, []);
    $input = jq_test_read_json(null);
    jq_test_emit(($input['enabled'] ?? null) === true, false, true);
}

if ($filter === $rulesetPredicate) {
    if (!$exitStatus || $raw || $nullInput || $inputFile !== null) {
        jq_test_fail('ruleset predicate used unexpected arguments');
    }
    jq_test_require_arguments($namedArguments, []);
    $input = jq_test_read_json(null);
    $ruleTypes = [];
    $rules = $input['rules'] ?? null;
    $rulesWellFormed = is_array($rules) && array_is_list($rules);
    if ($rulesWellFormed) {
        foreach ($rules as $rule) {
            if (
                !is_array($rule)
                || !array_key_exists('type', $rule)
                || !is_string($rule['type'])
            ) {
                $rulesWellFormed = false;
                break;
            }
            $ruleTypes[] = $rule['type'];
        }
    }
    $conditions = $input['conditions']['ref_name'] ?? null;
    $valid = $rulesWellFormed
        && ($input['target'] ?? null) === 'tag'
        && ($input['enforcement'] ?? null) === 'active'
        && array_key_exists('bypass_actors', $input)
        && $input['bypass_actors'] === []
        && is_array($conditions)
        && ($conditions['include'] ?? null) === ['~ALL']
        && ($conditions['exclude'] ?? null) === []
        && in_array('update', $ruleTypes, true)
        && in_array('deletion', $ruleTypes, true)
        && !in_array('creation', $ruleTypes, true);
    jq_test_emit($valid, false, true);
}

if (
    in_array(
        $filter,
        ['.name', '.source', '.source_type', '.updated_at'],
        true
    )
) {
    if ($exitStatus || !$raw || $nullInput || $inputFile !== null) {
        jq_test_fail('raw metadata filter used unexpected arguments');
    }
    jq_test_require_arguments($namedArguments, []);
    $input = jq_test_read_json(null);
    $field = substr($filter, 1);
    $value = $input[$field] ?? null;
    if (!is_string($value)) {
        jq_test_fail('raw metadata field is not a string', 4);
    }
    jq_test_emit($value, true, false);
}

if ($filter === $evidenceConstructor) {
    if ($exitStatus || $raw || !$nullInput || $inputFile !== null) {
        jq_test_fail('evidence constructor used unexpected arguments');
    }
    jq_test_require_arguments($namedArguments, [
        'repository',
        'release_tag',
        'git_commit',
        'ruleset_id',
        'ruleset_name',
        'ruleset_source',
        'ruleset_source_type',
        'ruleset_updated_at',
    ]);
    jq_test_emit([
        'format' => 'estab-github-release-policy-v1',
        'repository' => $namedArguments['repository'],
        'release_tag' => $namedArguments['release_tag'],
        'git_commit' => $namedArguments['git_commit'],
        'immutable_releases' => true,
        'tag_ruleset' => [
            'id' => $namedArguments['ruleset_id'],
            'name' => $namedArguments['ruleset_name'],
            'source' => $namedArguments['ruleset_source'],
            'source_type' => $namedArguments['ruleset_source_type'],
            'updated_at' => $namedArguments['ruleset_updated_at'],
            'target' => 'tag',
            'enforcement' => 'active',
            'include' => ['~ALL'],
            'exclude' => [],
            'bypass_actors' => [],
            'rules' => ['update', 'deletion'],
        ],
    ], false, false);
}

if ($filter === $evidencePredicate) {
    if (!$exitStatus || $raw || $nullInput || $inputFile === null) {
        jq_test_fail('evidence predicate used unexpected arguments');
    }
    jq_test_require_arguments($namedArguments, ['commit']);
    $input = jq_test_read_json($inputFile);
    $tagRuleset = $input['tag_ruleset'] ?? null;
    $valid = ($input['format'] ?? null)
            === 'estab-github-release-policy-v1'
        && ($input['repository'] ?? null) === 'e-stab/estab'
        && ($input['release_tag'] ?? null) === 'release-1.2.3'
        && ($input['git_commit'] ?? null) === $namedArguments['commit']
        && ($input['immutable_releases'] ?? null) === true
        && is_array($tagRuleset)
        && ($tagRuleset['include'] ?? null) === ['~ALL']
        && ($tagRuleset['bypass_actors'] ?? null) === []
        && ($tagRuleset['rules'] ?? null) === ['update', 'deletion'];
    jq_test_emit($valid, false, true);
}

if (
    in_array($filter, [
        'del(.bypass_actors)',
        '.bypass_actors = [{"actor_id": 1, "actor_type": "User"}]',
        '.rules = [{"type": "deletion"}]',
        '.rules += [{"type": "creation"}]',
        '.rules += ["invalid"]',
        '.conditions.ref_name.include = ["refs/tags/release-1.2.3"]',
    ], true)
) {
    if ($exitStatus || $raw || $nullInput || $inputFile !== null) {
        jq_test_fail('fixture mutation used unexpected arguments');
    }
    jq_test_require_arguments($namedArguments, []);
    $input = jq_test_read_json(null);
    switch ($filter) {
        case 'del(.bypass_actors)':
            unset($input['bypass_actors']);
            break;
        case '.bypass_actors = [{"actor_id": 1, "actor_type": "User"}]':
            $input['bypass_actors'] = [[
                'actor_id' => 1,
                'actor_type' => 'User',
            ]];
            break;
        case '.rules = [{"type": "deletion"}]':
            $input['rules'] = [['type' => 'deletion']];
            break;
        case '.rules += [{"type": "creation"}]':
            if (!is_array($input['rules'] ?? null)) {
                jq_test_fail('rules fixture is not an array', 4);
            }
            $input['rules'][] = ['type' => 'creation'];
            break;
        case '.rules += ["invalid"]':
            if (!is_array($input['rules'] ?? null)) {
                jq_test_fail('rules fixture is not an array', 4);
            }
            $input['rules'][] = 'invalid';
            break;
        case '.conditions.ref_name.include = ["refs/tags/release-1.2.3"]':
            if (!is_array($input['conditions']['ref_name'] ?? null)) {
                jq_test_fail('ref-name fixture is not an object', 4);
            }
            $input['conditions']['ref_name']['include'] = [
                'refs/tags/release-1.2.3',
            ];
            break;
    }
    jq_test_emit($input, false, false);
}

jq_test_fail('unsupported filter: ' . $filter);
PHP
    chmod 0755 "$fake_bin/jq"
    PATH="$fake_bin:$PATH"
    export PATH
    if printf '{}\n' | jq '.' \
        >"$fixture/unsupported-jq.stdout" \
        2>"$fixture/unsupported-jq.stderr"; then
        echo "Release policy test: jq double accepted an unknown filter" >&2
        exit 1
    fi
    grep -Fq 'unsupported filter: .' "$fixture/unsupported-jq.stderr"
fi

commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
good_ruleset='{
  "id": 42,
  "name": "Freeze every existing tag",
  "target": "tag",
  "source": "e-stab/estab",
  "source_type": "Repository",
  "enforcement": "active",
  "bypass_actors": [],
  "conditions": {
    "ref_name": {
      "include": ["~ALL"],
      "exclude": []
    }
  },
  "rules": [
    {"type": "update"},
    {"type": "deletion"}
  ],
  "updated_at": "2026-07-31T00:00:00Z"
}'
ruleset_policy_filter='
  .target == "tag"
  and .enforcement == "active"
  and has("bypass_actors")
  and .bypass_actors == []
  and .conditions.ref_name.include == ["~ALL"]
  and .conditions.ref_name.exclude == []
  and ([.rules[].type] | index("update")) != null
  and ([.rules[].type] | index("deletion")) != null
  and ([.rules[].type] | index("creation")) == null
'

if [ "$using_jq_test_double" -eq 1 ]; then
    malformed_rule_case=0
    while IFS= read -r malformed_ruleset; do
        malformed_rule_case=$((malformed_rule_case + 1))
        if printf '%s\n' "$malformed_ruleset" |
            jq -e "$ruleset_policy_filter" >/dev/null 2>&1; then
            printf '%s %s\n' \
                'Release policy test: jq double accepted malformed rule case' \
                "$malformed_rule_case" >&2
            exit 1
        fi
    done <<'EOF'
{"target":"tag","enforcement":"active","bypass_actors":[],"conditions":{"ref_name":{"include":["~ALL"],"exclude":[]}},"rules":[{"type":"update"},{"type":"deletion"},"invalid"]}
{"target":"tag","enforcement":"active","bypass_actors":[],"conditions":{"ref_name":{"include":["~ALL"],"exclude":[]}},"rules":[{"type":"update"},{"type":"deletion"},{}]}
{"target":"tag","enforcement":"active","bypass_actors":[],"conditions":{"ref_name":{"include":["~ALL"],"exclude":[]}},"rules":[{"type":"update"},{"type":"deletion"},{"type":7}]}
EOF
fi

run_policy()
{
    policy_ruleset=$1
    policy_remote_commit=${2:-$commit}
    policy_immutable_state=${3:-'{"enabled":true}'}
    PATH="$fake_bin:$PATH" \
        GH_TOKEN=fixture-policy-token \
        FAKE_RULESET_STATE="$policy_ruleset" \
        FAKE_REMOTE_COMMIT="$policy_remote_commit" \
        FAKE_IMMUTABLE_STATE="$policy_immutable_state" \
        "$policy_verifier" \
        e-stab/estab release-1.2.3 "$commit" 42
}

run_policy "$good_ruleset" >"$fixture/policy.json"
jq -e \
    --arg commit "$commit" '
      .format == "estab-github-release-policy-v1"
      and .repository == "e-stab/estab"
      and .release_tag == "release-1.2.3"
      and .git_commit == $commit
      and .immutable_releases == true
      and .tag_ruleset.include == ["~ALL"]
      and .tag_ruleset.bypass_actors == []
      and .tag_ruleset.rules == ["update", "deletion"]
    ' "$fixture/policy.json" >/dev/null

expect_failure()
{
    case_name=$1
    expected_message=$2
    ruleset=$3
    remote_commit=${4:-$commit}
    immutable_state=${5:-'{"enabled":true}'}
    if run_policy "$ruleset" "$remote_commit" "$immutable_state" \
        >"$fixture/$case_name.stdout" 2>"$fixture/$case_name.stderr"; then
        printf 'Release policy test: unsafe case passed: %s\n' \
            "$case_name" >&2
        exit 1
    fi
    grep -Fq "$expected_message" "$fixture/$case_name.stderr"
}

missing_bypass=$(printf '%s\n' "$good_ruleset" |
    jq 'del(.bypass_actors)')
expect_failure missing-bypass \
    'tag ruleset must actively forbid updates and deletions' \
    "$missing_bypass"

bypass_actor=$(printf '%s\n' "$good_ruleset" |
    jq '.bypass_actors = [{"actor_id": 1, "actor_type": "User"}]')
expect_failure bypass-actor \
    'tag ruleset must actively forbid updates and deletions' \
    "$bypass_actor"

missing_update=$(printf '%s\n' "$good_ruleset" |
    jq '.rules = [{"type": "deletion"}]')
expect_failure missing-update \
    'tag ruleset must actively forbid updates and deletions' \
    "$missing_update"

creation_rule=$(printf '%s\n' "$good_ruleset" |
    jq '.rules += [{"type": "creation"}]')
expect_failure creation-rule \
    'tag ruleset must actively forbid updates and deletions' \
    "$creation_rule"

malformed_rule=$(printf '%s\n' "$good_ruleset" |
    jq '.rules += ["invalid"]')
expect_failure malformed-rule \
    'tag ruleset must actively forbid updates and deletions' \
    "$malformed_rule"

exact_ref=$(printf '%s\n' "$good_ruleset" |
    jq '.conditions.ref_name.include = ["refs/tags/release-1.2.3"]')
expect_failure exact-ref \
    'tag ruleset must actively forbid updates and deletions' \
    "$exact_ref"

expect_failure wrong-commit \
    'remote release tag does not resolve to the workflow commit' \
    "$good_ruleset" bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb

expect_failure mutable-release-policy \
    'immutable releases are not enabled' \
    "$good_ruleset" "$commit" '{"enabled":false}'

workflow=$repo_root/.github/workflows/publish-images.yml

workflow_marker_line()
{
    workflow_marker_label=$1
    workflow_marker=$2
    workflow_marker_count=$(
        grep -Fxc "$workflow_marker" "$workflow" || true
    )
    if [ "$workflow_marker_count" -ne 1 ]; then
        printf 'Release policy test: expected one %s step, found %s\n' \
            "$workflow_marker_label" "$workflow_marker_count" >&2
        exit 1
    fi
    grep -nFx "$workflow_marker" "$workflow" | cut -d: -f1
}

draft_marker='      - name: Create and verify the complete release draft'
upload_marker='      - name: Upload publication evidence'
publish_marker='      - name: Make the fully verified draft visible'
retain_marker='      - name: Retain final GitHub publication policy evidence'
cleanup_marker="      - name: Delete only this run's still-private draft after failure"

draft_line=$(workflow_marker_line 'release draft' "$draft_marker")
upload_line=$(workflow_marker_line 'publication evidence upload' "$upload_marker")
publish_line=$(workflow_marker_line 'final release publish' "$publish_marker")
retain_line=$(workflow_marker_line 'final policy evidence retention' "$retain_marker")
cleanup_line=$(workflow_marker_line 'failed-draft cleanup' "$cleanup_marker")
if [ "$draft_line" -ge "$upload_line" ] ||
    [ "$upload_line" -ge "$publish_line" ] ||
    [ "$publish_line" -ge "$retain_line" ] ||
    [ "$retain_line" -ge "$cleanup_line" ]; then
    printf '%s\n' \
        'Release policy test: release workflow steps are out of order' >&2
    exit 1
fi

draft_contract=$fixture/release-draft.yml
publish_contract=$fixture/release-publish.yml
cleanup_contract=$fixture/release-cleanup.yml
draft_end=$((upload_line - 1))
publish_end=$((retain_line - 1))
sed -n "${draft_line},${draft_end}p" "$workflow" >"$draft_contract"
sed -n "${publish_line},${publish_end}p" "$workflow" >"$publish_contract"
sed -n "${cleanup_line},\$p" "$workflow" >"$cleanup_contract"

grep -Fq 'id: release_draft' "$draft_contract"
grep -Fq 'release_id=%s\n' "$draft_contract"
grep -Fq \
    'https://uploads.github.com/repos/$GITHUB_REPOSITORY/releases/$owned_release_id/assets?name=$asset_name' \
    "$draft_contract"
grep -Fq \
    'https://api.github.com/repos/$GITHUB_REPOSITORY/releases/assets/$asset_id' \
    "$draft_contract"
grep -Fq '.digest == $digest' "$draft_contract"
grep -Fq '.size == $size' "$draft_contract"
grep -Fq '.tag_name == $tag' "$draft_contract"
grep -Fq '.target_commitish == $target' "$draft_contract"
grep -Fq '.published_at == null' "$draft_contract"

grep -Fq \
    'OWNED_RELEASE_ID: ${{ steps.release_draft.outputs.release_id }}' \
    "$publish_contract"
grep -Fq \
    '"repos/$GITHUB_REPOSITORY/releases/$OWNED_RELEASE_ID"' \
    "$publish_contract"
grep -Fq 'expected_release_assets=' "$publish_contract"
grep -Fq -- '--slurpfile expected_assets' "$publish_contract"
grep -Fq ') == $expected_assets[0]' "$publish_contract"
grep -Fq '.tag_name == $tag' "$publish_contract"
grep -Fq '.target_commitish == $target' "$publish_contract"
grep -Fq '.published_at == null' "$publish_contract"
grep -Fq \
    'else (.published_at | type == "string" and length > 0)' \
    "$publish_contract"
grep -Fq \
    'https://api.github.com/repos/$GITHUB_REPOSITORY/releases/assets/$asset_id' \
    "$publish_contract"
grep -Fq 'publish_request="$RUNNER_TEMP/publish-owned-release.json"' \
    "$publish_contract"
grep -Fq 'gh api --method PATCH' "$publish_contract"
grep -Fq 'verify_owned_release "$published_response" false' \
    "$publish_contract"
grep -Fq '.immutable == true' "$publish_contract"
grep -Fq 'github-policy-before-publish.json' "$publish_contract"
grep -Fq 'github-policy-after-publish.json' "$publish_contract"

grep -Fq \
    "if: \${{ failure() && steps.release_draft.outputs.release_id != '' }}" \
    "$cleanup_contract"
grep -Fq "$cleanup_marker" "$cleanup_contract"
grep -Fq \
    'OWNED_RELEASE_ID: ${{ steps.release_draft.outputs.release_id }}' \
    "$cleanup_contract"
grep -Fq '.draft == true' "$cleanup_contract"
grep -Fq '.immutable == false' "$cleanup_contract"
grep -Fq '.published_at == null' "$cleanup_contract"
grep -Fq -- '--method DELETE' "$cleanup_contract"
if grep -Fq 'ESTAB_RELEASE_POLICY_TOKEN' "$cleanup_contract"; then
    printf '%s\n' \
        'Release policy test: cleanup unnecessarily receives policy token' >&2
    exit 1
fi

if grep -Eq 'gh release (create|upload|download|edit|delete) ' "$workflow"; then
    printf '%s\n' \
        'Release policy test: a tag-only release mutation remains' >&2
    exit 1
fi

printf 'Release policy tests: OK\n'
