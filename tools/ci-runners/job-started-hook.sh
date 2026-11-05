#!/usr/bin/env bash
# Runner job-started hook (ACTIONS_RUNNER_HOOK_JOB_STARTED). It resets every reused checkout under the runner's work
# directory before each job, so tracked files that a previous job modified cannot make actions/checkout fail with
# "Path '...' not uptodate; will not remove from working tree". A checkout that git cannot reset is emptied instead.
# The hook never fails: a failing hook would fail the job it should protect. Installed by tools/ci-runners/setup.sh.
set -u

WORK=${ORBIT_RUNNER_WORK:-/home/runner/work}

reset_workspace() {
  local workspace=$1
  [ -d "$workspace" ] || return 0
  if [ -d "$workspace/.git" ] \
    && git -C "$workspace" reset --hard --quiet \
    && git -C "$workspace" clean -ffdx --quiet; then
    return 0
  fi
  echo "job-started-hook: emptying $workspace; git could not reset it" >&2
  find "$workspace" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + 2>/dev/null || true
}

# Checkouts live at WORK/<repository>/<repository>. Runner internals (_temp, _actions, _tool, ...) start with "_".
for workspace in "$WORK"/[!_]*/[!_]*; do
  reset_workspace "$workspace"
done
exit 0
