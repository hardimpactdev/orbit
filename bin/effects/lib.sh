# Shared helpers for effect scripts. Sourced, not run.
# Every effect gets: WORK_ITEM, SUB_PROJECT, KEY, PLAN_COMMIT, RESULT (path of
# the result JSON to write), INPUTS (path of a JSON file with stage inputs).
set -euo pipefail

SCRATCH_ROOT="${SCRATCH_ROOT:-/fast/apps/orbit-swamp-scratch}"
BASE_CLONE="$SCRATCH_ROOT/base"
WT="$SCRATCH_ROOT/wt/$WORK_ITEM"
BRANCH="swamp/$WORK_ITEM"

log() { printf '%s %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }

# Write the result JSON atomically; the poller treats its presence as "done".
write_result() {
  local tmp="$RESULT.tmp.$$"
  printf '%s\n' "$1" > "$tmp"
  mv "$tmp" "$RESULT"
}

# Create the work item's worktree at the planned commit and install
# dependencies from the lockfiles (Project 46 setup steps, scoped). Idempotent.
ensure_worktree() {
  if [ ! -e "$WT/.git" ]; then
    log "creating worktree $WT on $BRANCH at ${PLAN_COMMIT:0:9}"
    git -C "$BASE_CLONE" worktree add -q -B "$BRANCH" "$WT" "$PLAN_COMMIT"
  fi
  if [ ! -e "$WT/vendor/autoload.php" ]; then
    log "composer install (root)"
    (cd "$WT" && composer install --no-interaction --no-ansi --no-progress -q)
  fi
  if [ ! -e "$WT/$SUB_PROJECT/vendor/autoload.php" ]; then
    log "composer install ($SUB_PROJECT)"
    (cd "$WT/$SUB_PROJECT" && composer install --no-interaction --no-ansi --no-progress -q)
  fi
}

# The commit that already carries this idempotency key, if any.
commit_for_key() {
  git -C "$WT" log --format=%H --grep="^Swamp-Idempotency-Key: $KEY\$" -n 1 "$PLAN_COMMIT..HEAD" 2>/dev/null || true
}

# Drop uncommitted work left by a killed attempt of this same key.
discard_partial_work() {
  git -C "$WT" reset -q --hard HEAD
  git -C "$WT" clean -qfd -- "$SUB_PROJECT"
}

commit_with_key() {
  local subject="$1"
  git -C "$WT" add -A -- "$SUB_PROJECT"
  if git -C "$WT" diff --cached --quiet; then return 1; fi
  git -C "$WT" -c user.name="orbit-swamp" -c user.email="orbit-swamp@beast.invalid" \
    commit -q -m "$subject" -m "Swamp-Work-Item: $WORK_ITEM" -m "Swamp-Idempotency-Key: $KEY"
}

json_tail() { tail -c "${2:-12000}" "$1" | jq -Rs .; }
