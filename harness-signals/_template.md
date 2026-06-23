# Signal: <Short Title>

Status: open
First seen: YYYY-MM-DD
Last seen: YYYY-MM-DD
Last reviewed: YYYY-MM-DD
Source worktree: <branch or path>
Source commit: <sha, branch, or none>
Signal type: <review-comment | failed-check | docs-conflict | e2e-failure | live-node | agent-mistake | setup | other>
Guardrail target: <path or planned path>
Guardrail change: <commit, pending, or none>
Related signals: <paths or none>
Superseded by: <path or none>
Tags: <comma-separated tags>

## Signal

Describe the observed failure, correction, or missing context in one or two
paragraphs. Include exact commands or review wording only when useful.

## Prior Occurrences

State whether this has appeared before. Link related records when present.

## Missing Guardrail

Explain what an agent did not know, could not discover, or was not prevented
from doing.

## Guardrail Change

Describe where the lesson landed: skill, docs, test, static check, harness
file, or follow-up.

## Verification

Name the command, review check, or reasoning that proves the guardrail target
is reachable and useful.

## Reappearance Check

State what a future agent should do if this signal appears again.

## Curation Notes

Record updates, consolidation decisions, stale reasons, retirement reasons, or
delete rationale when useful.
