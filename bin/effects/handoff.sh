#!/usr/bin/env bash
# handoff: run the sub-project's check and full test suite on the worktree HEAD.
# Read-only for the worktree. The result also carries the streak of consecutive
# failed handoffs for this work item, read from earlier handoff markers, so the
# factory can gate Orbit's "three reminders, the fourth asks for assistance".
source "$(dirname "$0")/lib.sh"
ensure_worktree

head=$(git -C "$WT" rev-parse HEAD)
out="$(dirname "$RESULT")/handoff.log"
log "handoff $SUB_PROJECT at ${head:0:9}"
set +e
(cd "$WT/$SUB_PROJECT" && composer check --no-interaction --no-ansi && composer test --no-interaction --no-ansi) > "$out" 2>&1
code=$?
set -e
status=$([ "$code" -eq 0 ] && echo succeeded || echo failed)

# Streak: earlier handoff results of this work item, oldest first, plus this one.
markers="$(dirname "$(dirname "$RESULT")")"
streak=0
for f in $(ls -d "$markers/$WORK_ITEM".handoff.c* 2>/dev/null | sort -V); do
  [ "$f" = "$(dirname "$RESULT")" ] && continue
  r="$f/result.json"; [ -f "$r" ] || continue
  if [ "$(jq -r .status "$r")" = failed ]; then streak=$((streak + 1)); else streak=0; fi
done
[ "$status" = failed ] && streak=$((streak + 1)) || streak=0

diffstat=$(git -C "$WT" diff --shortstat "$PLAN_COMMIT" HEAD -- "$SUB_PROJECT" | sed 's/^ *//')
write_result "$(jq -n --arg status "$status" --argjson code "$code" --arg head "$head" \
  --arg diffstat "$diffstat" --argjson streak "$streak" --argjson tail "$(json_tail "$out")" \
  '{status:$status, exitCode:$code, commit:$head, diffstat:$diffstat, consecutiveFailures:$streak, outputTail:$tail}')"
