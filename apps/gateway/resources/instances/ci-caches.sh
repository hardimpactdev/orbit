# Orbit @orbit/ci-caches: import only the recorded code baseline's ref received from the Gateway mirror.
# Missing or invalid refs leave checks cold. Required tooling and cache writes remain setup failures.
set -eu
if [ ! -f bin/ci-cache-ref ] || [ ! -x bin/tia-cache ]; then
    echo 'CI cache import tooling is missing from this checkout.' >&2
    exit 1
fi
branch=$(git symbolic-ref --quiet --short HEAD || true)
default=$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD || true)
if [ "origin/$branch" = "$default" ]; then
    commit=$(git rev-parse --verify HEAD)
else
    commit=$(git rev-parse --verify --quiet refs/worktree/orbit/ci-baseline || true)
fi
if [ -z "$commit" ]; then
    echo 'CI caches cold: no recorded code baseline.'
else
    python3 bin/ci-cache-ref import --commit "$commit"
fi
