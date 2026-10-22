#!/usr/bin/env bash
# implement: one Codex turn (gpt-6.1-sol via CLIProxyAPI) with the failing
# handoff output, the open review findings, or a consult answer. Its edits are
# committed under the key, so a retried key never commits twice. A killed turn
# is discarded and rerun: that costs a second LLM turn but no second commit.
source "$(dirname "$0")/lib.sh"
ensure_worktree
dir="$(dirname "$RESULT")"

done_commit=$(commit_for_key)
if [ -n "$done_commit" ]; then
  log "key $KEY already committed as ${done_commit:0:9}"
  write_result "$(jq -n --arg c "$done_commit" --arg s "$(git -C "$WT" log -1 --format=%s "$done_commit")" \
    '{status:"succeeded", outcome:"done", commit:$c, summary:$s, llmTurns:0}')"
  exit 0
fi
discard_partial_work

feedback=$(jq -r '.feedback // empty | "Exit code \(.exitCode). Output tail:\n\(.outputTail)"' "$INPUTS")
findings=$(jq -r '[.findings.findings // [] | .[] | select(.resolved != true)] | map("- \(.id) (\(.severity)): \(.description)") | join("\n")' "$INPUTS")
answer=$(jq -r '.consult // empty | "Q: \(.question)\nA: \(.answer)"' "$INPUTS")
files=$(git -C "$WT" diff --name-only "$PLAN_COMMIT" HEAD -- "$SUB_PROJECT" | head -50)

cat > "$dir/prompt.md" <<EOF
You are working in a git worktree of the Orbit monorepo. The sub-project
$SUB_PROJECT just had its Composer dependencies updated within their declared
constraints. Make its checks and tests pass again: \`composer check\` and
\`composer test\` inside $SUB_PROJECT.

Rules:
- Change only files under $SUB_PROJECT. Do not change version constraints in
  composer.json. Do not run composer update. Do not commit, push or open PRs.
- Fix the root cause in application code or tests. No skipped tests, no
  baseline entries, no ignore rules.
- If you cannot continue without a decision from a person, stop and make the
  last line of your reply: BLOCKED: <one question>
- Otherwise make the last line of your reply: DONE: <one-line summary>

Files changed by the update so far:
$files

${feedback:+The last handoff check failed.
$feedback
}${findings:+Open review findings to fix:
$findings
}${answer:+A reviewer answered your earlier question:
$answer
}
EOF

log "codex turn for $KEY"
set +e
codex exec -C "$WT" -s workspace-write --skip-git-repo-check -o "$dir/last-message.txt" \
  "$(cat "$dir/prompt.md")" > "$dir/codex.log" 2>&1
code=$?
set -e
last=$(tail -n 1 "$dir/last-message.txt" 2>/dev/null || true)
if [ "$code" -ne 0 ]; then
  write_result "$(jq -n --argjson code "$code" --argjson tail "$(json_tail "$dir/codex.log" 6000)" \
    '{status:"failed", outcome:"error", exitCode:$code, outputTail:$tail, llmTurns:1}')"
  exit 0
fi
if [[ "$last" == BLOCKED:* ]]; then
  discard_partial_work
  write_result "$(jq -n --arg q "${last#BLOCKED: }" '{status:"succeeded", outcome:"blocked", question:$q, llmTurns:1}')"
  exit 0
fi
summary="${last#DONE: }"
commit_with_key "fix($SUB_PROJECT): ${summary:0:120}" || true
write_result "$(jq -n --arg c "$(commit_for_key)" --arg s "$summary" \
  '{status:"succeeded", outcome:"done", commit:(if $c == "" then null else $c end), summary:$s, llmTurns:1}')"
