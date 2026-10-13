#!/usr/bin/env bash
# Checks out the default branch $2 that the Gateway sent into the checkout $1 and prints its commit. Runs as the
# managed user in a workspace template builder.
set -eu
git -c core.hooksPath=/dev/null -C "$1" checkout --quiet --force -B "$2" "refs/remotes/origin/$2"
git -C "$1" rev-parse --verify 'HEAD^{commit}'
