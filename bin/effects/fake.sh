#!/usr/bin/env bash
# fake: scripted stage results for engine tests (caps, loops, overrides) with
# no git, Composer or LLM work. Used when $SCRATCH_ROOT/fake/<workItem>.json
# exists: {"<stage>": [<result>, ...]}. The n-th run of a stage gets the n-th
# result (the last one repeats). Results are keyed like real ones, so a retried
# key never advances the script.
source "$(dirname "$0")/lib.sh"
stage="$STAGE"
scenario="$SCRATCH_ROOT/fake/$WORK_ITEM.json"
markers="$(dirname "$(dirname "$RESULT")")"
n=0
for f in "$markers/$WORK_ITEM.$stage".c*; do
  [ "$f" = "$(dirname "$RESULT")" ] && continue
  [ -f "$f/result.json" ] && n=$((n + 1))
done
result=$(jq -c --arg s "$stage" --argjson n "$n" '.[$s] as $r | $r[if $n < ($r|length) then $n else ($r|length)-1 end]' "$scenario")

if [ "$stage" = handoff ]; then
  # Same streak rule as handoff.sh.
  streak=0
  for f in $(ls -d "$markers/$WORK_ITEM".handoff.c* 2>/dev/null | sort -V); do
    [ "$f" = "$(dirname "$RESULT")" ] && continue
    r="$f/result.json"; [ -f "$r" ] || continue
    if [ "$(jq -r .status "$r")" = failed ]; then streak=$((streak + 1)); else streak=0; fi
  done
  [ "$(jq -r .status <<<"$result")" = failed ] && streak=$((streak + 1)) || streak=0
  result=$(jq -c --argjson s "$streak" '. + {consecutiveFailures:$s, commit:"0000000fake", exitCode:(if .status=="failed" then 1 else 0 end)}' <<<"$result")
fi
log "fake $stage run $((n + 1)): $result"
write_result "$result"
