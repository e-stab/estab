#!/usr/bin/env bash

set -euo pipefail

die()
{
    printf 'eStab release policy: %s\n' "$*" >&2
    exit 1
}

if [[ $# -ne 4 ]]; then
    printf 'Usage: %s OWNER/REPOSITORY RELEASE_TAG EXPECTED_COMMIT RULESET_ID\n' \
        "$0" >&2
    exit 64
fi

repository=$1
release_tag=$2
expected_commit=$3
ruleset_id=$4

[[ -n "${GH_TOKEN:-}" ]] ||
    die "GH_TOKEN is required"
command -v gh >/dev/null 2>&1 ||
    die "gh is required"
command -v jq >/dev/null 2>&1 ||
    die "jq is required"
[[ "$repository" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] ||
    die "repository must use OWNER/REPOSITORY syntax"
[[ "$release_tag" =~ ^[a-z0-9][a-z0-9._-]{0,127}$ ]] ||
    die "release tag is not canonical"
[[ "$expected_commit" =~ ^[a-f0-9]{40}$ ]] ||
    die "expected commit is not a canonical SHA-1 object ID"
[[ "$ruleset_id" =~ ^[1-9][0-9]*$ ]] ||
    die "ruleset ID must be a positive integer"

api()
{
    gh api \
        --method GET \
        --header "Accept: application/vnd.github+json" \
        --header "X-GitHub-Api-Version: 2026-03-10" \
        "$@"
}

if ! immutable_state=$(api "repos/$repository/immutable-releases" 2>&1); then
    printf '%s\n' "$immutable_state" >&2
    die "cannot read immutable-release policy"
fi
jq -e '.enabled == true' <<<"$immutable_state" >/dev/null ||
    die "immutable releases are not enabled"

if ! ruleset_state=$(api \
    "repos/$repository/rulesets/$ruleset_id?includes_parents=true" 2>&1); then
    printf '%s\n' "$ruleset_state" >&2
    die "cannot read the configured tag ruleset"
fi
jq -e '
  .target == "tag"
  and .enforcement == "active"
  and has("bypass_actors")
  and .bypass_actors == []
  and .conditions.ref_name.include == ["~ALL"]
  and .conditions.ref_name.exclude == []
  and ([.rules[].type] | index("update")) != null
  and ([.rules[].type] | index("deletion")) != null
  and ([.rules[].type] | index("creation")) == null
' <<<"$ruleset_state" >/dev/null ||
    die "tag ruleset must actively forbid updates and deletions for ~ALL tags, allow creation, and have no bypass actors"

if ! remote_commit=$(gh api \
    --method GET \
    --header "Accept: application/vnd.github.sha" \
    --header "X-GitHub-Api-Version: 2026-03-10" \
    "repos/$repository/commits/tags/$release_tag" 2>&1); then
    printf '%s\n' "$remote_commit" >&2
    die "cannot resolve the remote release tag"
fi
remote_commit=${remote_commit//$'\r'/}
remote_commit=${remote_commit//$'\n'/}
[[ "$remote_commit" =~ ^[a-f0-9]{40}$ ]] ||
    die "remote release tag did not resolve to a canonical commit"
[[ "$remote_commit" == "$expected_commit" ]] ||
    die "remote release tag does not resolve to the workflow commit"

jq -n \
    --arg repository "$repository" \
    --arg release_tag "$release_tag" \
    --arg git_commit "$remote_commit" \
    --arg ruleset_id "$ruleset_id" \
    --arg ruleset_name "$(jq -r '.name' <<<"$ruleset_state")" \
    --arg ruleset_source "$(jq -r '.source' <<<"$ruleset_state")" \
    --arg ruleset_source_type "$(jq -r '.source_type' <<<"$ruleset_state")" \
    --arg ruleset_updated_at "$(jq -r '.updated_at' <<<"$ruleset_state")" \
    '{
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
    }'
