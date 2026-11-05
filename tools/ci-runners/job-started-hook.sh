#!/usr/bin/env bash
# Runner job-started hook (ACTIONS_RUNNER_HOOK_JOB_STARTED). It resets every reused checkout under the runner's work
# directory before each job, so tracked files that a previous job modified cannot make actions/checkout fail with
# "Path '...' not uptodate; will not remove from working tree". A checkout that git cannot reset is emptied instead.
# So is a checkout in sparse mode: a previous job's sparse checkout leaves skip-worktree entries whose files are still on
# disk, `reset --hard` does not touch them, and the next checkout's `git sparse-checkout disable` then fails on them.
# The hook never fails: a failing hook would fail the job it should protect. Installed by tools/ci-runners/setup.sh.
set -u

WORK=${ORBIT_RUNNER_WORK:-/home/runner/work}

# Sparse mode is set in the config or left behind as skip-worktree entries in the index.
is_sparse() {
  [ "$(git -C "$1" config --bool --get core.sparseCheckout 2>/dev/null)" = true ] \
    || git -C "$1" ls-files -v 2>/dev/null | grep -q '^S'
}

reset_workspace() {
  local workspace=$1
  # Only a git checkout is reset; any other directory under WORK is not ours to touch. A worktree has a .git file.
  [ -e "$workspace/.git" ] || return 0
  if ! is_sparse "$workspace" \
    && git -C "$workspace" sparse-checkout disable \
    && git -C "$workspace" reset --hard --quiet \
    && git -C "$workspace" clean -ffdx --quiet; then
    return 0
  fi
  echo "job-started-hook: emptying $workspace; it is sparse or git could not reset it" >&2
  find "$workspace" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + 2>/dev/null || true
}

# Checkouts live at WORK/<repository>/<repository>. Runner internals (_temp, _actions, _tool, ...) start with "_".
for workspace in "$WORK"/[!_]*/[!_]*; do
  reset_workspace "$workspace"
done
exit 0
