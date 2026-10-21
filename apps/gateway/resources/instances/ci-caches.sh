# Orbit @orbit/ci-caches: import the Pint and PHPStan caches of only the recorded code baseline's ref from the Gateway mirror.
# Missing or invalid refs leave checks cold. Required tooling and cache writes remain setup failures.
set -eu
if [ ! -f bin/ci-cache-ref ]; then
    echo 'CI cache import tooling is missing from this checkout.' >&2
    exit 1
fi
branch=$(git symbolic-ref --quiet --short HEAD || true)
default=$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD || true)
if [ "origin/$branch" = "$default" ]; then
    commit=$(git rev-parse --verify HEAD)
else
    commit=$(git rev-parse --verify --quiet refs/worktree/orbit/ci-baseline || true)
    # Another branch at a main commit, such as a fresh agent branch, uses that commit's own caches.
    if [ -z "$commit" ] && [ -n "$default" ] && git merge-base --is-ancestor HEAD "$default" 2>/dev/null; then
        commit=$(git rev-parse --verify HEAD)
    fi
fi
if [ -z "$commit" ]; then
    echo 'CI caches cold: no recorded code baseline.'
else
    python3 bin/ci-cache-ref import --commit "$commit"
fi
