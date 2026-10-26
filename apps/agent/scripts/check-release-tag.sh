#!/usr/bin/env bash
# Validates an agent release before it is published (ADR 0225): the tag is `agent-v<full commit SHA>`, the
# delivery manifest names that commit and is the build of the tested head, the commit is an ancestor of that
# head and the last commit there that changed apps/agent, and no release with the tag exists yet.
set -euo pipefail

if [[ $# -ne 4 ]]; then
    echo "Usage: $0 <tag> <repository> <manifest> <tested head SHA>" >&2
    exit 2
fi

tag=$1
repository=$2
manifest=$3
head=$4
commit=${tag#agent-v}

if [[ "$tag" != "agent-v$commit" || ! "$commit" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Invalid agent release tag: $tag" >&2
    exit 1
fi

if [[ "$(jq -r .commit "$manifest")" != "$commit" ]]; then
    echo "The delivery manifest names $(jq -r .commit "$manifest"), not $commit" >&2
    exit 1
fi

if ! git merge-base --is-ancestor "$commit" "$head"; then
    echo "$commit is not an ancestor of the tested head $head" >&2
    exit 1
fi

last=$("$(dirname "${BASH_SOURCE[0]}")/source-commit" "$head")
if [[ "$last" != "$commit" ]]; then
    echo "The last commit that changed apps/agent at $head is $last, not $commit" >&2
    exit 1
fi

if gh release view "$tag" --repo "$repository" >/dev/null 2>&1; then
    echo "Release $tag already exists; refusing to replace its assets" >&2
    exit 1
fi

echo "Validated new release $tag"
