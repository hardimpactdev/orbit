#!/usr/bin/env bash
# mechanical: `composer update` in one sub-project, the same command Orbit's
# instance:dependencies:update runs. No LLM. Exactly once per key: a commit
# carrying the key means the update already landed.
source "$(dirname "$0")/lib.sh"
ensure_worktree

done_commit=$(commit_for_key)
if [ -z "$done_commit" ]; then
  discard_partial_work
  before=$(mktemp)
  git -C "$WT" show "HEAD:$SUB_PROJECT/composer.lock" > "$before"
  log "composer update in $SUB_PROJECT"
  set +e
  (cd "$WT/$SUB_PROJECT" && composer update --no-interaction --no-ansi --no-progress --no-audit) > "$(dirname "$RESULT")/composer.log" 2>&1
  code=$?
  set -e
  if [ "$code" -ne 0 ]; then
    write_result "$(jq -n --argjson code "$code" --argjson tail "$(json_tail "$(dirname "$RESULT")/composer.log")" \
      '{status:"failed", exitCode:$code, outputTail:$tail}')"
    exit 0
  fi
  commit_with_key "chore(deps): update $SUB_PROJECT within constraints" || true
  done_commit=$(commit_for_key)
else
  log "key $KEY already committed as ${done_commit:0:9}"
  before=$(mktemp)
  git -C "$WT" show "$done_commit^:$SUB_PROJECT/composer.lock" > "$before"
fi

after=$(mktemp)
git -C "$WT" show "HEAD:$SUB_PROJECT/composer.lock" > "$after"
# Every package whose locked version moved, direct or transitive.
changes=$(jq -n --slurpfile a "$before" --slurpfile b "$after" '
  def vers(l): [(l.packages // [])[], (l["packages-dev"] // [])[]] | map({(.name): .version}) | add // {};
  (vers($a[0])) as $old | (vers($b[0])) as $new
  | [$new | to_entries[] | select($old[.key] != .value) | {name:.key, from:($old[.key] // null), to:.value}]')
write_result "$(jq -n --arg commit "${done_commit:-}" --arg sp "$SUB_PROJECT" --argjson changes "$changes" \
  '{status:"succeeded", exitCode:0, subProject:$sp, commit:(if $commit == "" then null else $commit end),
    lockfileChanged:($commit != ""), packages:$changes}')"
