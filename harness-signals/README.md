# Orbit Harness Signal Ledger

This directory stores curated repo-development signals that should compound
Orbit's harness over time. A signal record is not a raw log. It is a small
learning artifact that explains what happened, whether it appeared before, and
which guardrail target absorbed the lesson.

The pattern is inspired by
[Compound Engineering](https://github.com/everyinc/compound-engineering-plugin):
each unit of work should make later units easier by preserving the useful
lesson, not just the final diff.

## When To Record

Create or update a signal record when a signal is likely to recur, was expensive
to diagnose, affected correctness or safety, or revealed missing repo-wide
guidance. Do not record ordinary typo fixes, one-off local mistakes, or every
failed command.

Raw `.orbit/` artifacts and post-feature review notes create candidate signals,
not records by default. Before a candidate becomes a record or guardrail, the
fresh post-feature distillation reviewer should classify it and the feature
orchestrator should adjudicate it against `HARNESS_SIGNALS.md`. A reviewed
`no durable guardrail needed` result is healthy; do not add a record just to
prove the review happened.

Good candidates:

- A human correction that should not need to be repeated
- A failed verification lane that exposed missing routing guidance
- A recurring agent mistake across worktrees
- A docs conflict that showed unclear authority
- A live-node or runtime-user issue that static tests missed
- A signal that reappears after a guardrail already claimed to cover it

## Before Starting Work

Agents do not need to read every record. Search the ledger with concrete terms
from the task, changed area, or failure:

```bash
rg -n "doctor|e2e|worktree|guardrail|runtime user" harness-signals
```

If a matching record exists, read it before choosing a fix path. If the same
signal is recurring, assume the existing guardrail is insufficient until proven
otherwise.

## Record Lifecycle

Use one of these statuses:

| Status | Meaning |
|--------|---------|
| `open` | Signal is recorded, but no guardrail target has absorbed it yet. |
| `guarded` | A guardrail target was updated and verified. |
| `recurring` | The signal reappeared after a guardrail target was updated. |
| `stale` | The record may be outdated or misleading, but the right action is not clear yet. |
| `retired` | Later work has not reproduced the signal, or a stronger guardrail superseded it. |

Retirement is deliberately manual in this slice. Prefer marking a record
`retired` before deleting it, so future agents can still discover the history
while the signal cools down.

## Curation

Signal records should make future work easier. If the ledger starts producing
too many weak matches, curate it instead of expecting agents to read around the
noise.

Run a focused curation pass when:

- A search returns several records for the same underlying signal
- A `guarded` signal reappears in a new worktree
- A record points at files, skills, commands, or docs that no longer exist
- A record is repeatedly skipped because it no longer helps decisions
- Roughly ten feature or bugfix worktrees have landed since the last broad pass

Use these outcomes:

| Outcome | Action |
|---------|--------|
| Keep | Record is still accurate and useful. Do not edit just to leave a review breadcrumb. |
| Update | Facts drifted, but the signal and guardrail target are still valid. Fix paths, commits, status, or verification. |
| Consolidate | Two or more records cover the same signal. Merge unique recurrence history into the canonical record, then delete the redundant files. |
| Mark recurring | The signal reappeared after a guardrail target changed. Set `Status: recurring` and tighten or replace the guardrail target. |
| Mark stale | The record seems outdated, but the right replacement or deletion is ambiguous. Add a short stale reason. |
| Retire | The signal has cooled down or a stronger guardrail superseded it, but the history remains useful. Set `Status: retired`. |
| Delete | The record no longer has retrieval value, is fully redundant, or points at a dead concern with no active lesson. Delete it; git history is the archive. |

Do not create an `_archived/` directory. Archive folders pollute searches and
hide old guidance without removing it. If a deleted record is needed later, git
history can recover it.

Before deleting a record, search for inbound references:

```bash
rg -n "signal-slug-or-filename" .
```

If another record or harness doc relies on it for context, consolidate or
retire instead of deleting.

## Record Shape

Copy `_template.md` and name the file:

```text
YYYY-MM-DD-short-signal-slug.md
```

Keep the file short. The goal is fast retrieval:

- What happened?
- Has it happened before?
- Which guardrail target handled it?
- How was the target verified?
- What should a future agent do if it appears again?

## Relationship To Guardrails

The signal record is evidence and history. It is not the guardrail.

Durable guardrail targets live in places like `AGENTS.md`, `HARNESS.md`,
`.orbit/loop.md`, `HARNESS_SIGNALS.md`, `.agents/skills/**`,
`.agents/review-personas/**`, product docs, tests, or static checks. A guarded
signal should point to the target that now guides or blocks future work.
