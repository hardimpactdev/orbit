#!/usr/bin/env bash
# consult: the reviewer (Claude) answers an implementer's BLOCKED question.
# Read-only; one LLM turn.
source "$(dirname "$0")/lib.sh"
ensure_worktree
dir="$(dirname "$RESULT")"
question=$(jq -r '.blocked.question // "No question recorded."' "$INPUTS")

cat > "$dir/prompt.md" <<EOF
You are the reviewer for a dependency update of $SUB_PROJECT in the Orbit
monorepo (worktree $WT). The implementer stopped with this question:

$question

Answer it so the implementer can continue without a person, if you can. Do
not edit files. If only a person can decide, say so in one sentence.
Answer with one JSON object only: {"answer": "<text>", "needsPerson": true|false}
EOF

set +e
(cd "$WT" && claude -p --model claude-opus-5-5 --output-format json --allowedTools "Read" "Grep" "Glob" \
  < "$dir/prompt.md") > "$dir/claude.json" 2> "$dir/claude.err"
code=$?
set -e
obj=$(jq -r '.result // empty' "$dir/claude.json" | sed -n '/^{/,/^}/p' | jq -c '{answer, needsPerson}' 2>/dev/null || true)
if [ "$code" -ne 0 ] || [ -z "$obj" ]; then
  write_result "$(jq -n --argjson code "$code" '{status:"failed", exitCode:$code, error:"no valid answer JSON", llmTurns:1}')"
  exit 0
fi
write_result "$(jq -n --arg q "$question" --argjson o "$obj" '{status:"succeeded", question:$q, answer:$o.answer, needsPerson:$o.needsPerson, llmTurns:1}')"
