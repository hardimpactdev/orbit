#!/usr/bin/env bash
# review: one read-only Claude turn (claude-opus-5-5 via CLIProxyAPI) over the
# work item's change since the plan commit. Writes findings in the factory's
# `kind: findings` shape. Read-only, so a rerun of the key only costs a turn.
source "$(dirname "$0")/lib.sh"
ensure_worktree
dir="$(dirname "$RESULT")"
head=$(git -C "$WT" rev-parse HEAD)

lockdiff=$(jq -r '.mechanical.packages // [] | map("\(.name) \(.from) -> \(.to)") | join("\n")' "$INPUTS")
previous=$(jq -r '[.previous.findings // [] | .[]] | map("- \(.id) (\(.severity)): \(.description)") | join("\n")' "$INPUTS")
git -C "$WT" diff "$PLAN_COMMIT" HEAD -- "$SUB_PROJECT" ':(exclude)*composer.lock' > "$dir/change.diff"

cat > "$dir/prompt.md" <<EOF
Review a dependency update in the Orbit monorepo, sub-project $SUB_PROJECT,
worktree $WT, commits ${PLAN_COMMIT:0:9}..${head:0:9}. The handoff check
(composer check + composer test) passed on ${head:0:9}.

Locked package changes:
$lockdiff

Source diff (composer.lock excluded):
$(cat "$dir/change.diff")
${previous:+
Findings from the previous review round. Check each one again against the
current code; keep its id if it still applies:
$previous
}
Look for: breaking changes in the updated packages that tests may not cover
(read the vendor changelogs under $SUB_PROJECT/vendor if useful), unsafe code
changes, skipped or weakened tests, and constraint edits. Do not edit files.

Answer with one JSON object only, no prose, no code fence:
{"summary": "<one paragraph>", "findings": [{"id": "REV-1", "severity": "critical|high|medium|low", "category": "<word>", "description": "<what and where>"}]}
Use an empty findings list when the change is fine.
EOF

log "claude review for $KEY"
set +e
(cd "$WT" && claude -p --model claude-opus-5-5 --output-format json \
  --allowedTools "Read" "Grep" "Glob" "Bash(git log:*)" "Bash(git diff:*)" "Bash(git show:*)" \
  < "$dir/prompt.md") > "$dir/claude.json" 2> "$dir/claude.err"
code=$?
set -e
text=$(jq -r '.result // empty' "$dir/claude.json" 2>/dev/null || true)
# Accept the object even when the model wraps it in a fence.
review=$(printf '%s' "$text" | sed -n '/^{/,/^}/p' | jq -c '{summary, findings: [.findings[] | {id, severity, category, description}]}' 2>/dev/null || true)
if [ "$code" -ne 0 ] || [ -z "$review" ]; then
  write_result "$(jq -n --argjson code "$code" --arg t "${text:0:4000}" --arg e "$(tail -c 2000 "$dir/claude.err")" \
    '{status:"failed", exitCode:$code, error:"no valid review JSON", text:$t, stderr:$e, llmTurns:1}')"
  exit 0
fi
write_result "$(jq -n --argjson r "$review" --arg head "$head" '{status:"succeeded", commit:$head, review:$r, llmTurns:1}')"
